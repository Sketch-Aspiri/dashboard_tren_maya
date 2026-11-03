<?php

namespace App\Services\Secciones;

use App\Enums\EstadoOrigen;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\CalculadorDiferencias;
use App\Services\Nube\ClasificadorErrorNube;
use App\Services\Nube\Datos\Diferencias;
use App\Services\Nube\Datos\ListadoRemoto;
use App\Services\Nube\Datos\ResultadoCiclo;
use App\Services\Nube\NubeTransitoriaException;
use App\Services\Nube\ProveedorNubeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Motor de sincronización de un origen (carpeta o archivo de la nube):
 * lista → calcula diferencias → las aplica → deja el resumen del origen.
 * Los fallos pasajeros (429, 5xx, sin conexión) se relanzan como
 * NubeTransitoriaException para que el job reintente con espera; el resto
 * queda en el origen y en la bitácora sin reintentos infinitos.
 */
final class SincronizadorOrigen
{
    public const OK = 'ok';

    public const RETENIDO = 'retenido';

    public const ERROR = 'error';

    public const RECONEXION = 'requiere_reconexion';

    public const ELIMINADO = 'origen_eliminado';

    public const OCUPADO = 'ocupado';

    private const MAX_MENSAJE = 490;

    /** Al menos el $timeout de SincronizarOrigenJob (900 s). */
    private const BLOQUEO_SEGUNDOS = 960;

    public function __construct(
        private readonly ProveedorNubeFactory $proveedores,
        private readonly CalculadorDiferencias $calculador,
        private readonly AplicadorDiferencias $aplicador,
        private readonly ClasificadorErrorNube $clasificador,
    ) {}

    /**
     * @return self::OK|self::RETENIDO|self::ERROR|self::RECONEXION|self::ELIMINADO|self::OCUPADO
     *
     * @throws NubeTransitoriaException
     */
    public function sincronizar(SeccionOrigen $origen, ?User $usuario = null): string
    {
        $bloqueo = Cache::lock('nube-origen-'.$origen->id, self::BLOQUEO_SEGUNDOS);

        if (! $bloqueo->get()) {
            return self::OCUPADO;
        }

        try {
            return $this->ejecutar($origen->loadMissing('cuenta', 'seccion'), $usuario);
        } finally {
            $bloqueo->release();
        }
    }

    /**
     * Último intento fallido del job: queda en el origen y en la bitácora.
     */
    public function registrarFalloDefinitivo(SeccionOrigen $origen, string $mensaje): void
    {
        $this->registrarError($origen, $mensaje);
    }

    private function ejecutar(SeccionOrigen $origen, ?User $usuario): string
    {
        if ($origen->cuenta !== null && ! $origen->cuenta->estaActiva()) {
            return $this->cerrarConEstado($origen, EstadoOrigen::RequiereReconexion, self::RECONEXION);
        }

        try {
            $listado = $this->proveedores->para($origen)->listarConResumen($origen);
            $diferencias = $this->calcular($origen, $listado);
            $resultado = $this->aplicador->aplicar($origen, $diferencias, $usuario);
        } catch (NubeSyncException $e) {
            return $this->manejarFalloDeNube($origen, $e);
        } catch (DiscoInsuficienteException $e) {
            $this->registrarError($origen, $e->getMessage());

            return self::ERROR;
        } catch (NubeTransitoriaException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->registrarError($origen, __('Error inesperado al sincronizar. Revisa el registro del servidor.'));

            return self::ERROR;
        }

        $this->guardarResumen($origen, $listado, $diferencias, $resultado);

        return $diferencias->cambioMasivoRetenido ? self::RETENIDO : self::OK;
    }

    private function calcular(SeccionOrigen $origen, ListadoRemoto $listado): Diferencias
    {
        $confirmada = (bool) ($origen->resumen['confirmar_baja_masiva'] ?? false);

        return $this->calculador->calcular($listado->archivos, $this->locales($origen), $confirmada);
    }

    /**
     * Documentos del origen con papelera; sin el texto extraído (pesa mucho).
     *
     * @return Collection<int, SeccionArchivo>
     */
    private function locales(SeccionOrigen $origen): Collection
    {
        return $origen->archivos()->withTrashed()->whereNotNull('remoto_id')
            ->select([
                'id', 'seccion_id', 'seccion_fila_id', 'seccion_origen_id', 'nombre_original', 'ruta', 'mime', 'extension',
                'tamano', 'estacion_id', 'subido_por', 'contenido_hash', 'remoto_id', 'remoto_ruta', 'remoto_version',
                'remoto_modificado_en', 'created_at', 'updated_at', 'deleted_at',
            ])
            ->with('seccion')
            ->get();
    }

    private function manejarFalloDeNube(SeccionOrigen $origen, NubeSyncException $e): string
    {
        return match ($this->clasificador->clasificar($e, $origen->cuenta)) {
            ClasificadorErrorNube::RECONEXION => $this->cerrarConEstado($origen, EstadoOrigen::RequiereReconexion, self::RECONEXION),
            ClasificadorErrorNube::ELIMINADO => $this->cerrarConEstado($origen, EstadoOrigen::OrigenEliminado, self::ELIMINADO),
            ClasificadorErrorNube::TRANSITORIO => throw new NubeTransitoriaException($e->getMessage(), 0, $e),
            default => $this->fallar($origen, $e->getMessage()),
        };
    }

    private function fallar(SeccionOrigen $origen, string $mensaje): string
    {
        $this->registrarError($origen, $mensaje);

        return self::ERROR;
    }

    /**
     * Pone el origen en un estado que exige acción humana: deja de reintentarse.
     */
    private function cerrarConEstado(SeccionOrigen $origen, EstadoOrigen $estado, string $retorno): string
    {
        $mensaje = $estado === EstadoOrigen::RequiereReconexion
            ? __('La cuenta de nube necesita reconectarse. El Administrador debe hacerlo en Conexiones de nube; los documentos se conservan.')
            : __('La carpeta o el archivo ya no existe en la nube (o dejó de estar compartido). Los documentos se conservan; desvincula el origen o restablece el acceso.');

        $origen->forceFill(['estado' => $estado])->save();
        $this->registrarError($origen, $mensaje);

        return $retorno;
    }

    private function guardarResumen(SeccionOrigen $origen, ListadoRemoto $listado, Diferencias $diferencias, ResultadoCiclo $resultado): void
    {
        $omitidos = $listado->omitidos;

        foreach ($resultado->omitidos as $motivo => $cantidad) {
            $omitidos[$motivo] = ($omitidos[$motivo] ?? 0) + $cantidad;
        }

        $retenido = $diferencias->cambioMasivoRetenido;
        $mensajeRetenido = __('Cambio masivo retenido: la nube no devolvió ningún archivo y antes había documentos. No se archivó nada; confirma el cambio o revisa el acceso.');
        $error = $resultado->error ?? ($retenido ? $mensajeRetenido : $resultado->aviso);

        $origen->forceFill([
            'sincronizado_en' => now(),
            'sincronizacion_error' => $error === null ? null : Str::limit($error, self::MAX_MENSAJE, ''),
            'resumen' => [
                'archivos' => $origen->archivos()->count(),
                'omitidos' => $omitidos,
                'ultimo_error' => $resultado->error,
                'cambio_masivo_retenido' => $retenido,
                'ciclo' => [
                    'altas' => $resultado->altas,
                    'actualizados' => $resultado->actualizados,
                    'archivados' => $resultado->archivados,
                    'restaurados' => $resultado->restaurados,
                ],
            ],
        ])->save();
    }

    private function registrarError(SeccionOrigen $origen, string $mensaje): void
    {
        $mensaje = Str::limit($mensaje, self::MAX_MENSAJE, '');
        $resumen = $origen->resumen ?? [];
        $resumen['ultimo_error'] = $mensaje;

        $origen->forceFill(['sincronizacion_error' => $mensaje, 'resumen' => $resumen])->save();

        activity('seccion')
            ->performedOn($origen)
            ->event('sincronizacion_fallida')
            ->withProperties(['proveedor' => $origen->proveedor, 'nombre' => $origen->nombre, 'motivo' => $mensaje])
            ->log('Falló la sincronización de un origen con la nube.');
    }
}
