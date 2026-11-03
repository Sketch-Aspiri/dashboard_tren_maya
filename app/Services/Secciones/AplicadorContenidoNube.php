<?php

namespace App\Services\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Http\Requests\Secciones\StoreSeccionArchivoRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionImportacion;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * Aplica a un documento el contenido nuevo que bajó de la nube: lo valida con
 * las reglas de una subida manual, reemplaza el archivo y vuelve a cargar las
 * tablas que ya salían de él ("actualizar por columna llave"). Las tablas solo
 * se recargan si el documento ya se había cargado antes y hay una columna
 * llave para coincidir filas; nunca se crean ni se borran datos "a ciegas".
 */
final class AplicadorContenidoNube
{
    public function __construct(
        private readonly ArchivoService $archivos,
        private readonly ExtractorDocumentos $extractor,
        private readonly ImportacionService $importaciones,
        private readonly LibroImportacionService $libros,
    ) {}

    /**
     * Misma validación que una subida manual. Una página de inicio de
     * sesión o de "archivo no disponible" nunca reemplaza al documento.
     */
    public function validar(SeccionArchivo $archivo, string $temporal): void
    {
        $this->validarContenido($archivo->nombre_original, $temporal);
    }

    /**
     * @throws NubeSyncException
     */
    public function validarContenido(string $nombre, string $temporal): void
    {
        $candidato = new UploadedFile($temporal, $nombre, null, null, true);

        $falla = Validator::make(['archivo' => $candidato], ['archivo' => ['required', ...StoreSeccionArchivoRequest::reglasDeContenido()]])->fails();

        if ($falla) {
            throw new NubeSyncException(__('El enlace no entregó el documento esperado (puede pedir iniciar sesión o ya no estar compartido).'));
        }
    }

    /**
     * Guarda la nueva versión, actualiza el registro y recarga las tablas en
     * una transacción. El archivo anterior se borra solo si todo salió bien.
     */
    public function reemplazar(SeccionArchivo $archivo, SeccionOrigen $origen, string $temporal, string $hash, ?User $usuario): ?string
    {
        $disco = Storage::disk($this->archivos->disco());
        $rutaAnterior = $archivo->ruta;
        $rutaNueva = $disco->putFileAs("secciones/{$archivo->seccion_id}", new File($temporal), Str::uuid()->toString().'.'.$archivo->extension);

        if ($rutaNueva === false) {
            throw new NubeSyncException(__('No se pudo guardar la nueva versión del documento.'));
        }

        try {
            $aviso = DB::transaction(function () use ($archivo, $origen, $temporal, $hash, $rutaNueva, $disco, $usuario): ?string {
                $tamano = (int) filesize($temporal);

                $archivo->disableLogging();
                $archivo->forceFill([
                    'ruta' => $rutaNueva,
                    'mime' => (string) (new File($temporal))->getMimeType(),
                    'tamano' => $tamano,
                    'texto_extraido' => $this->archivos->puedeLeerse($tamano) ? $this->extractor->texto($disco->path($rutaNueva), $archivo->extension) : null,
                    'contenido_hash' => $hash,
                ])->save();
                $archivo->enableLogging();

                $aviso = $this->recargarTablas($archivo, $origen);

                activity('seccion')
                    ->causedBy($usuario)
                    ->performedOn($archivo)
                    ->event('documento_sincronizado')
                    ->withProperties(['proveedor' => $origen->proveedor, 'nombre' => $archivo->nombre_original, 'tamano' => $tamano, 'aviso' => $aviso])
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
    private function recargarTablas(SeccionArchivo $archivo, SeccionOrigen $origen): ?string
    {
        $seccion = $archivo->seccion;
        $previa = SeccionImportacion::query()
            ->where('seccion_archivo_id', $archivo->id)
            ->where('estado', EstadoImportacion::Aplicada->value)
            ->orderByDesc('id')
            ->first(['id', 'resumen', 'seccion_tabla_id']);

        if ($archivo->seccion_fila_id || ! $origen->alimenta_tablas || $seccion->solo_documentos || $previa === null || ! $this->archivos->puedeLeerse($archivo->tamano)) {
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
}
