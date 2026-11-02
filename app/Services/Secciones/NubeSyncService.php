<?php

namespace App\Services\Secciones;

use App\Enums\EstadoOrigen;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\Contracts\ProveedorNube;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Mantiene un documento al día con su origen de la nube: lo descarga, y si el
 * contenido cambió (sha256) reemplaza el archivo y vuelve a cargar sus tablas
 * (AplicadorContenidoNube). Si algo falla se conserva la versión anterior y el
 * motivo queda en `sincronizacion_error` del origen.
 */
final class NubeSyncService
{
    public const SIN_CAMBIOS = 'sin_cambios';

    public const ACTUALIZADO = 'actualizado';

    public const ERROR = 'error';

    public const OCUPADO = 'ocupado';

    private const MAX_MENSAJE = 490;

    /** Al menos el $timeout de SincronizarOrigenJob (900 s), para que el bloqueo no expire con el job en curso. */
    private const BLOQUEO_SEGUNDOS = 960;

    public function __construct(
        private readonly EnlaceNubeResolver $resolver,
        private readonly ArchivoService $archivos,
        private readonly AplicadorContenidoNube $aplicador,
        private readonly ProveedorNube $proveedor,
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

        DB::transaction(function () use ($archivo, $enlace, $resuelto, $ruta, $usuario): void {
            $origen = $archivo->origen ?? new SeccionOrigen;
            $origen->forceFill([
                'seccion_id' => $archivo->seccion_id,
                'tipo' => SeccionOrigen::TIPO_ARCHIVO,
                'proveedor' => $resuelto['proveedor'],
                'enlace_original' => trim($enlace),
                'nombre' => $archivo->nombre_original,
                'estado' => EstadoOrigen::Activo,
                'sincronizacion_error' => null,
                'creado_por' => $origen->creado_por ?? $usuario?->id,
            ])->save();

            $archivo->disableLogging();
            $archivo->forceFill([
                'seccion_origen_id' => $origen->id,
                'contenido_hash' => is_file($ruta) ? hash_file('sha256', $ruta) : null,
            ])->save();
            $archivo->enableLogging();
        });

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
        $proveedor = $archivo->origen?->proveedor;

        DB::transaction(function () use ($archivo): void {
            // El enlace compartido no debe quedar guardado en la fila borrada.
            $archivo->origen?->forceFill(['enlace_original' => ''])->save();
            $archivo->origen?->delete();

            $archivo->disableLogging();
            // `origen_url` es la columna heredada: un enlace desvinculado no debe seguir guardado.
            $archivo->forceFill(['seccion_origen_id' => null, 'contenido_hash' => null, 'origen_url' => null])->save();
            $archivo->enableLogging();
        });

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($archivo)
            ->event('documento_desvinculado_nube')
            ->withProperties(['proveedor' => $proveedor, 'nombre' => $archivo->nombre_original])
            ->log('Documento desvinculado de la nube.');
    }

    /**
     * Sincroniza un origen activo (lo que ejecuta el job). Hoy un origen es
     * un solo documento; las carpetas llegan en fases siguientes.
     *
     * @return self::SIN_CAMBIOS|self::ACTUALIZADO|self::ERROR|self::OCUPADO
     */
    public function sincronizarOrigen(SeccionOrigen $origen, ?User $usuario = null): string
    {
        $archivo = $origen->archivos()->with('seccion')->first();

        if ($archivo === null) {
            // Se pausa para no repetir el error (y su registro en la bitácora) en cada ciclo.
            SeccionOrigen::query()->whereKey($origen->id)->update(['estado' => EstadoOrigen::Pausado->value]);
            $this->registrarError($origen, null, __('El origen ya no tiene un documento asociado: se pausó.'));

            return self::ERROR;
        }

        $archivo->setRelation('origen', $origen);

        return $this->sincronizar($archivo, $usuario);
    }

    /**
     * @return self::SIN_CAMBIOS|self::ACTUALIZADO|self::ERROR|self::OCUPADO
     */
    public function sincronizar(SeccionArchivo $archivo, ?User $usuario = null): string
    {
        $origen = $archivo->origen;

        if ($origen === null) {
            return self::ERROR;
        }

        $bloqueo = Cache::lock('nube-sync-'.$archivo->id, self::BLOQUEO_SEGUNDOS);

        if (! $bloqueo->get()) {
            return self::OCUPADO;
        }

        $temporal = (string) tempnam(sys_get_temp_dir(), 'nube');

        try {
            $remoto = $this->proveedor->listar($origen)[0];
            $this->proveedor->descargar($origen, $remoto, $temporal);
            $hash = (string) hash_file('sha256', $temporal);

            if ($hash === $archivo->contenido_hash) {
                $this->marcarSincronizado($origen, null);

                return self::SIN_CAMBIOS;
            }

            $this->aplicador->validar($archivo, $temporal);
            $aviso = $this->aplicador->reemplazar($archivo, $origen, $temporal, $hash, $usuario);
            $this->marcarSincronizado($origen, $aviso);

            return self::ACTUALIZADO;
        } catch (NubeSyncException $e) {
            $this->registrarError($origen, $archivo, $e->getMessage());
        } catch (Throwable $e) {
            report($e);
            $this->registrarError($origen, $archivo, __('Error inesperado al sincronizar. Revisa el registro del servidor.'));
        } finally {
            @unlink($temporal);
            $bloqueo->release();
        }

        return self::ERROR;
    }

    private function marcarSincronizado(SeccionOrigen $origen, ?string $aviso): void
    {
        SeccionOrigen::query()->whereKey($origen->id)->update([
            'sincronizado_en' => now(),
            'sincronizacion_error' => $aviso === null ? null : Str::limit($aviso, self::MAX_MENSAJE, ''),
        ]);
    }

    private function registrarError(SeccionOrigen $origen, ?SeccionArchivo $archivo, string $mensaje): void
    {
        SeccionOrigen::query()->whereKey($origen->id)->update(['sincronizacion_error' => Str::limit($mensaje, self::MAX_MENSAJE, '')]);

        $registro = activity('seccion')
            ->event('sincronizacion_fallida')
            ->withProperties(['proveedor' => $origen->proveedor, 'nombre' => $archivo?->nombre_original ?? $origen->nombre, 'motivo' => $mensaje]);

        if ($archivo !== null) {
            $registro->performedOn($archivo);
        }

        $registro->log('Falló la sincronización de un documento con la nube.');
    }
}
