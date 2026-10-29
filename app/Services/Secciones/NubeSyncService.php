<?php

namespace App\Services\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Http\Requests\Secciones\StoreSeccionArchivoRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionImportacion;
use App\Models\SeccionTabla;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Mantiene un documento al día con su enlace compartido de la nube: lo
 * descarga, y si el contenido cambió (sha256) reemplaza el archivo y vuelve
 * a cargar sus tablas "actualizando por columna llave" (mismas reglas que
 * arrastrar el documento). Si algo falla se conserva la versión anterior
 * y el motivo queda en `sincronizacion_error`.
 *
 * Las tablas solo se recargan si el documento ya se había cargado antes y
 * si hay una columna llave para coincidir filas; nunca se crean ni se
 * borran datos "a ciegas".
 */
final class NubeSyncService
{
    public const SIN_CAMBIOS = 'sin_cambios';

    public const ACTUALIZADO = 'actualizado';

    public const ERROR = 'error';

    public const OCUPADO = 'ocupado';

    private const MAX_MENSAJE = 490;

    private const BLOQUEO_SEGUNDOS = 600;

    public function __construct(
        private readonly EnlaceNubeResolver $resolver,
        private readonly ArchivoService $archivos,
        private readonly ExtractorDocumentos $extractor,
        private readonly ImportacionService $importaciones,
        private readonly LibroImportacionService $libros,
    ) {}

    /**
     * Vincula un documento ya guardado con un enlace compartido. La versión
     * guardada se toma como la actual: la primera sincronización solo
     * actúa si el archivo de la nube es distinto.
     */
    public function vincular(SeccionArchivo $archivo, string $enlace, ?User $usuario): SeccionArchivo
    {
        $resuelto = $this->resolver->resolver($enlace);
        $ruta = $this->archivos->rutaAbsoluta($archivo);

        $archivo->disableLogging();
        $archivo->forceFill([
            'origen_url' => trim($enlace),
            'origen_proveedor' => $resuelto['proveedor'],
            'origen_hash' => is_file($ruta) ? hash_file('sha256', $ruta) : null,
            'sincronizacion_activa' => true,
            'sincronizacion_error' => null,
        ])->save();
        $archivo->enableLogging();

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($archivo)
            ->event('documento_vinculado_nube')
            ->withProperties(['proveedor' => $resuelto['proveedor'], 'nombre' => $archivo->nombre_original])
            ->log('Documento vinculado a un enlace de la nube.');

        return $archivo;
    }

    public function desvincular(SeccionArchivo $archivo, ?User $usuario): void
    {
        $proveedor = $archivo->origen_proveedor;

        $archivo->disableLogging();
        $archivo->forceFill([
            'origen_url' => null,
            'origen_proveedor' => null,
            'origen_hash' => null,
            'sincronizacion_activa' => false,
            'sincronizado_en' => null,
            'sincronizacion_error' => null,
        ])->save();
        $archivo->enableLogging();

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($archivo)
            ->event('documento_desvinculado_nube')
            ->withProperties(['proveedor' => $proveedor, 'nombre' => $archivo->nombre_original])
            ->log('Documento desvinculado de la nube.');
    }

    /**
     * @return self::SIN_CAMBIOS|self::ACTUALIZADO|self::ERROR|self::OCUPADO
     */
    public function sincronizar(SeccionArchivo $archivo, ?User $usuario = null): string
    {
        $bloqueo = Cache::lock('nube-sync-'.$archivo->id, self::BLOQUEO_SEGUNDOS);

        if (! $bloqueo->get()) {
            return self::OCUPADO;
        }

        $temporal = (string) tempnam(sys_get_temp_dir(), 'nube');

        try {
            $this->descargar((string) $archivo->origen_url, $temporal);
            $hash = (string) hash_file('sha256', $temporal);

            if ($hash === $archivo->origen_hash) {
                $this->marcarSincronizado($archivo, null);

                return self::SIN_CAMBIOS;
            }

            $this->validarContenido($archivo, $temporal);
            $aviso = $this->reemplazar($archivo, $temporal, $hash, $usuario);
            $this->marcarSincronizado($archivo, $aviso);

            return self::ACTUALIZADO;
        } catch (NubeSyncException $e) {
            $this->registrarError($archivo, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->registrarError($archivo, __('Error inesperado al sincronizar. Revisa el registro del servidor.'));
        } finally {
            @unlink($temporal);
            $bloqueo->release();
        }

        return self::ERROR;
    }

    private function descargar(string $enlace, string $destino): void
    {
        $url = $this->resolver->resolver($enlace)['descarga'];
        $maximo = (int) config('secciones.max_archivo_kb') * 1024;

        try {
            $respuesta = Http::timeout((int) config('secciones.nube.timeout_segundos'))
                ->withHeaders(['User-Agent' => 'DashboardJefeZona-Sync/1.0'])
                ->withOptions([
                    'sink' => $destino,
                    'allow_redirects' => [
                        'max' => (int) config('secciones.nube.max_redirecciones'),
                        'strict' => true,
                        'protocols' => ['https'],
                        'on_redirect' => function ($request, $respuesta, UriInterface $uri): void {
                            if (! $this->resolver->hostPermitido($uri->getHost())) {
                                throw new NubeSyncException(__('El enlace redirige a un sitio no permitido.'));
                            }
                        },
                    ],
                    // Corta la descarga en cuanto rebasa el tope, sin esperar a terminarla.
                    'progress' => function ($total, $descargado) use ($maximo): void {
                        if ($descargado > $maximo) {
                            throw new NubeSyncException($this->mensajeTamano());
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException|RequestException $e) {
            if ($e->getPrevious() instanceof NubeSyncException) {
                throw $e->getPrevious();
            }

            throw new NubeSyncException(__('No se pudo conectar con la nube. Se reintentará en la próxima revisión.'), 0, $e);
        }

        if ($respuesta->failed()) {
            throw new NubeSyncException(__('La nube respondió con error :codigo. Revisa que el enlace siga compartido.', ['codigo' => $respuesta->status()]));
        }

        if ((int) filesize($destino) > $maximo) {
            throw new NubeSyncException($this->mensajeTamano());
        }
    }

    /**
     * Misma validación que una subida manual. Una página de inicio de
     * sesión o de "archivo no disponible" nunca reemplaza al documento.
     */
    private function validarContenido(SeccionArchivo $archivo, string $temporal): void
    {
        $candidato = new UploadedFile($temporal, $archivo->nombre_original, null, null, true);

        $falla = Validator::make(['archivo' => $candidato], ['archivo' => ['required', ...StoreSeccionArchivoRequest::reglasDeContenido()]])->fails();

        if ($falla) {
            throw new NubeSyncException(__('El enlace no entregó el documento esperado (puede pedir iniciar sesión o ya no estar compartido).'));
        }
    }

    /**
     * Guarda la nueva versión, actualiza el registro y recarga las tablas en
     * una transacción. El archivo anterior se borra solo si todo salió bien.
     */
    private function reemplazar(SeccionArchivo $archivo, string $temporal, string $hash, ?User $usuario): ?string
    {
        $disco = Storage::disk($this->archivos->disco());
        $rutaAnterior = $archivo->ruta;
        $rutaNueva = $disco->putFileAs("secciones/{$archivo->seccion_id}", new File($temporal), Str::uuid()->toString().'.'.$archivo->extension);

        if ($rutaNueva === false) {
            throw new NubeSyncException(__('No se pudo guardar la nueva versión del documento.'));
        }

        try {
            $aviso = DB::transaction(function () use ($archivo, $temporal, $hash, $rutaNueva, $disco, $usuario): ?string {
                $tamano = (int) filesize($temporal);

                $archivo->disableLogging();
                $archivo->forceFill([
                    'ruta' => $rutaNueva,
                    'mime' => (string) (new File($temporal))->getMimeType(),
                    'tamano' => $tamano,
                    'texto_extraido' => $this->archivos->puedeLeerse($tamano) ? $this->extractor->texto($disco->path($rutaNueva), $archivo->extension) : null,
                    'origen_hash' => $hash,
                ])->save();
                $archivo->enableLogging();

                $aviso = $this->recargarTablas($archivo);

                activity('seccion')
                    ->causedBy($usuario)
                    ->performedOn($archivo)
                    ->event('documento_sincronizado')
                    ->withProperties(['proveedor' => $archivo->origen_proveedor, 'nombre' => $archivo->nombre_original, 'tamano' => $tamano, 'aviso' => $aviso])
                    ->log('Documento actualizado desde la nube.');

                return $aviso;
            });
        } catch (Throwable $e) {
            $disco->delete($rutaNueva);

            throw $e;
        }

        $disco->delete($rutaAnterior);

        return $aviso;
    }

    /**
     * Vuelve a cargar las tablas que ya salían de este documento. Devuelve
     * un aviso (no un error) si alguna hoja no pudo coincidirse por falta de
     * columna llave: el documento sí se reemplazó.
     */
    private function recargarTablas(SeccionArchivo $archivo): ?string
    {
        $seccion = $archivo->seccion;
        $previa = SeccionImportacion::query()
            ->where('seccion_archivo_id', $archivo->id)
            ->where('estado', EstadoImportacion::Aplicada->value)
            ->orderByDesc('id')
            ->first(['id', 'resumen', 'seccion_tabla_id']);

        if ($archivo->seccion_fila_id || $seccion->solo_documentos || $previa === null || ! $this->archivos->puedeLeerse($archivo->tamano)) {
            return null;
        }

        $tablas = $this->extractor->tablas($this->archivos->rutaAbsoluta($archivo), $archivo->extension);

        if ($tablas === []) {
            return __('El documento nuevo ya no trae tablas legibles: se actualizó el archivo pero no las tablas.');
        }

        return count($tablas) >= 2
            ? $this->recargarLibro($archivo, $tablas)
            : $this->recargarHoja($archivo, $tablas, $this->tablaDeCargaPrevia($previa, $seccion));
    }

    /**
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     */
    private function recargarHoja(SeccionArchivo $archivo, array $tablas, SeccionTabla $destino): ?string
    {
        $importacion = $this->importaciones->desdeTablas($archivo->seccion, $archivo, $tablas, SeccionImportacion::ORIGEN_ARCHIVO, null, $destino);
        $propuesta = $this->importaciones->propuesta($importacion, 0, null, $destino);
        $modo = $this->modoPara($destino->filas()->exists(), $propuesta['llave']);

        if ($modo === null) {
            $this->importaciones->descartar($importacion);

            return $this->avisoSinLlave($destino->nombre);
        }

        $this->importaciones->aplicar($importacion, $this->opciones(0, $propuesta, $modo), null);

        return null;
    }

    /**
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     */
    private function recargarLibro(SeccionArchivo $archivo, array $tablas): ?string
    {
        $importacion = $this->importaciones->desdeTablas($archivo->seccion, $archivo, $tablas, SeccionImportacion::ORIGEN_ARCHIVO, null);
        $hojas = [];
        $sinLlave = [];

        foreach ($this->libros->propuestas($importacion) as $indice => $propuesta) {
            if ($propuesta['vacia']) {
                continue;
            }

            $modo = $this->modoPara(! $propuesta['destino_nuevo'], $propuesta['llave']);

            if ($modo === null) {
                $sinLlave[] = $propuesta['nombre'];

                continue;
            }

            $hojas[$indice] = $this->opciones($indice, $propuesta, $modo);
        }

        if ($hojas === []) {
            $this->importaciones->descartar($importacion);
        } else {
            $this->libros->aplicar($importacion, new OpcionesLibro(ModoImportacion::Actualizar, $hojas), null);
        }

        return $sinLlave === [] ? null : $this->avisoSinLlave(implode(', ', $sinLlave));
    }

    /**
     * Tabla vacía o nueva: agregar. Con datos: solo si hay llave para
     * coincidir filas; null si no se puede cargar sin duplicar.
     */
    private function modoPara(bool $tieneFilas, ?int $llave): ?ModoImportacion
    {
        return match (true) {
            ! $tieneFilas => ModoImportacion::Agregar,
            $llave !== null => ModoImportacion::Actualizar,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $propuesta
     */
    private function opciones(int $indice, array $propuesta, ModoImportacion $modo): OpcionesImportacion
    {
        return new OpcionesImportacion(
            tabla: $indice,
            filaEncabezado: $propuesta['fila_encabezado'],
            mapeo: array_map('strval', $propuesta['mapeo']),
            tipos: array_map(fn ($tipo) => TipoColumna::from($tipo), $propuesta['tipos']),
            modo: $modo,
            llave: $modo === ModoImportacion::Actualizar ? $propuesta['llave'] : null,
        );
    }

    /**
     * Tabla donde cayó la última carga de un documento de una sola hoja.
     */
    private function tablaDeCargaPrevia(SeccionImportacion $previa, Seccion $seccion): SeccionTabla
    {
        $resumen = $previa->resumen ?? [];
        $id = $resumen['tabla_id'] ?? ($resumen['hojas'][0]['tabla_id'] ?? $previa->seccion_tabla_id);
        $tabla = $id ? SeccionTabla::query()->where('seccion_id', $seccion->id)->find($id) : null;

        return $tabla ?? $seccion->tablaActiva();
    }

    private function avisoSinLlave(string $tablas): string
    {
        return __('Documento actualizado, pero no se recargaron las tablas (:tablas): no hay una columna llave única para coincidir las filas. Cárgalas a mano desde el documento.', ['tablas' => $tablas]);
    }

    private function mensajeTamano(): string
    {
        return __('El archivo de la nube pesa más de :max MB.', ['max' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]);
    }

    private function marcarSincronizado(SeccionArchivo $archivo, ?string $aviso): void
    {
        SeccionArchivo::query()->whereKey($archivo->id)->update([
            'sincronizado_en' => now(),
            'sincronizacion_error' => $aviso === null ? null : Str::limit($aviso, self::MAX_MENSAJE, ''),
        ]);
    }

    private function registrarError(SeccionArchivo $archivo, string $mensaje): void
    {
        SeccionArchivo::query()->whereKey($archivo->id)->update(['sincronizacion_error' => Str::limit($mensaje, self::MAX_MENSAJE, '')]);

        activity('seccion')
            ->performedOn($archivo)
            ->event('sincronizacion_fallida')
            ->withProperties(['proveedor' => $archivo->origen_proveedor, 'nombre' => $archivo->nombre_original, 'motivo' => $mensaje])
            ->log('Falló la sincronización de un documento con la nube.');
    }
}
