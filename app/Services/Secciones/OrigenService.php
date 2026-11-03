<?php

namespace App\Services\Secciones;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Jobs\SincronizarOrigenJob;
use App\Models\NubeCuenta;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Nube\ProveedorNubeFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Alta, cambios y baja de los orígenes de nube de una sección (carpetas o
 * archivos vinculados con una cuenta OAuth o con un enlace público). La
 * sincronización en sí la hace SincronizadorOrigen desde el job.
 */
final class OrigenService
{
    public function __construct(
        private readonly ProveedorNubeFactory $proveedores,
        private readonly EnlaceNubeResolver $resolver,
    ) {}

    /**
     * Rechaza un enlace que no sea https de un sitio del proveedor de la
     * cuenta (o, sin cuenta, de uno de los proveedores permitidos). Nada se
     * pide a la red antes de pasar por aquí.
     *
     * @throws ValidationException
     */
    public function validarEnlace(?NubeCuenta $cuenta, string $enlace): void
    {
        if ($cuenta === null) {
            $this->resolver->resolver($enlace);

            return;
        }

        $partes = parse_url(trim($enlace));
        $host = is_array($partes) ? strtolower((string) ($partes['host'] ?? '')) : '';
        $esHttps = is_array($partes) && ($partes['scheme'] ?? '') === 'https' && ! isset($partes['user'], $partes['pass']) && ($partes['port'] ?? 443) === 443;
        $delProveedor = collect($cuenta->proveedor->hostsDeEnlace())
            ->contains(fn (string $dominio): bool => $host === $dominio || str_ends_with($host, '.'.$dominio));

        if (! $esHttps || $host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || ! $delProveedor) {
            throw ValidationException::withMessages([
                'enlace' => __('El enlace debe ser una dirección https de :nube.', ['nube' => $cuenta->proveedor->label()]),
            ]);
        }
    }

    /**
     * Qué es lo que apunta el enlace y cuánto contiene, sin guardar nada.
     *
     * @throws NubeSyncException
     */
    public function inspeccionar(?NubeCuenta $cuenta, string $enlace): OrigenRemoto
    {
        return $this->proveedores->paraCuenta($cuenta)->inspeccionar(trim($enlace), $cuenta);
    }

    /**
     * @throws NubeSyncException
     * @throws ValidationException
     */
    public function vincular(Seccion $seccion, ?NubeCuenta $cuenta, string $enlace, ?bool $alimentaTablas, bool $incluirSubcarpetas, User $usuario): SeccionOrigen
    {
        if ($cuenta !== null) {
            Gate::forUser($usuario)->authorize('usar', $cuenta);
        }

        // Se vuelve a inspeccionar en el servidor: nada de lo que muestre el cliente se toma como cierto.
        $remoto = $this->inspeccionar($cuenta, $enlace);
        $this->evitarDuplicado($seccion, $cuenta, $remoto);

        $origen = new SeccionOrigen;
        $origen->forceFill([
            'seccion_id' => $seccion->id,
            'nube_cuenta_id' => $cuenta?->id,
            'tipo' => $remoto->tipo,
            'proveedor' => $cuenta !== null ? $cuenta->proveedor->value : $this->resolver->resolver($enlace)['proveedor'],
            'remoto_drive_id' => $remoto->driveId,
            'remoto_item_id' => $remoto->itemId,
            'enlace_original' => trim($enlace),
            'nombre' => $remoto->nombre,
            'incluir_subcarpetas' => $incluirSubcarpetas,
            'alimenta_tablas' => $alimentaTablas ?? ! $seccion->solo_documentos,
            'estado' => EstadoOrigen::Activo,
            'creado_por' => $usuario->id,
        ])->save();

        $this->registrar('origen_vinculado', $origen, $usuario, 'Origen de nube vinculado a la sección.', [
            'tipo' => $origen->tipo,
            'cuenta' => $cuenta?->cuenta_email,
            'cuenta_id' => $cuenta?->id,
            'cuenta_proveedor' => $cuenta?->proveedor->value,
            'archivos' => $remoto->archivos,
        ]);

        SincronizarOrigenJob::dispatch($origen->id, $usuario->id);

        return $origen;
    }

    /**
     * @param  array{estado?: string, alimenta_tablas?: bool, incluir_subcarpetas?: bool, confirmar_baja_masiva?: bool}  $datos
     *
     * @throws ValidationException
     */
    public function actualizar(SeccionOrigen $origen, array $datos, User $usuario): SeccionOrigen
    {
        $cambios = array_intersect_key($datos, array_flip(['alimenta_tablas', 'incluir_subcarpetas']));

        if (isset($datos['estado'])) {
            $estado = EstadoOrigen::from($datos['estado']);

            if ($estado === EstadoOrigen::Activo && $origen->cuenta !== null && ! $origen->cuenta->estaActiva()) {
                throw ValidationException::withMessages(['estado' => __('La cuenta de nube necesita reconectarse antes de reanudar.')]);
            }

            $cambios['estado'] = $estado->value;
        }

        $confirmaBaja = ($datos['confirmar_baja_masiva'] ?? false) === true;
        $atributos = $cambios;

        if ($confirmaBaja) {
            $atributos['resumen'] = array_merge($origen->resumen ?? [], ['confirmar_baja_masiva' => true]);
            $cambios['confirmar_baja_masiva'] = true;
        }

        $origen->forceFill($atributos)->save();

        $this->registrar('origen_actualizado', $origen, $usuario, 'Origen de nube actualizado.', $cambios);

        return $origen;
    }

    /**
     * Quita el origen. Con `$conservar` los documentos quedan como normales
     * (sin vínculo con la nube); si no, se archivan (se pueden restaurar).
     */
    public function desvincular(SeccionOrigen $origen, bool $conservar, User $usuario): void
    {
        $total = DB::transaction(function () use ($origen, $conservar, $usuario): int {
            $total = $origen->archivos()->count();

            $conservar ? $this->liberarDocumentos($origen) : $this->archivarDocumentos($origen, $usuario);
            $this->liberarTablas($origen);

            // El enlace compartido no debe quedar guardado en la fila borrada.
            $origen->forceFill(['enlace_original' => ''])->save();
            $origen->delete();

            return $total;
        });

        $this->registrar('origen_desvinculado', $origen, $usuario, 'Origen de nube desvinculado de la sección.', [
            'documentos' => $total,
            'conservados' => $conservar,
        ]);
    }

    public function sincronizarAhora(SeccionOrigen $origen, User $usuario): void
    {
        SincronizarOrigenJob::dispatch($origen->id, $usuario->id);
    }

    /**
     * Los orígenes en espera de reconexión vuelven a activarse cuando su
     * cuenta ya está activa otra vez.
     */
    public function reactivarReconectados(): int
    {
        return SeccionOrigen::query()
            ->where('estado', EstadoOrigen::RequiereReconexion->value)
            ->whereHas('cuenta', fn ($q) => $q->where('estado', EstadoCuentaNube::Activa->value))
            ->update(['estado' => EstadoOrigen::Activo->value, 'sincronizacion_error' => null]);
    }

    /**
     * Las tablas espejo se conservan como tablas normales (editables); el mapeo
     * ya no tiene origen al que obedecer.
     */
    private function liberarTablas(SeccionOrigen $origen): void
    {
        SeccionTabla::withTrashed()->where('seccion_origen_id', $origen->id)->toBase()->update(['seccion_origen_id' => null]);
        $origen->mapeos()->delete();
    }

    private function liberarDocumentos(SeccionOrigen $origen): void
    {
        $origen->archivos()->withTrashed()->update([
            'seccion_origen_id' => null,
            'remoto_id' => null,
            'remoto_ruta' => null,
            'remoto_version' => null,
            'remoto_modificado_en' => null,
            'contenido_hash' => null,
        ]);
    }

    private function archivarDocumentos(SeccionOrigen $origen, User $usuario): void
    {
        $origen->archivos()->each(function (SeccionArchivo $archivo) use ($origen, $usuario): void {
            $archivo->disableLogging();
            $archivo->delete();

            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($archivo)
                ->event('documento_archivado_nube')
                ->withProperties(['proveedor' => $origen->proveedor, 'origen' => $origen->nombre, 'nombre' => $archivo->nombre_original, 'ruta' => $archivo->remoto_ruta])
                ->log('Documento archivado: se desvinculó su origen de la nube.');
        });
    }

    /**
     * @throws ValidationException
     */
    private function evitarDuplicado(Seccion $seccion, ?NubeCuenta $cuenta, OrigenRemoto $remoto): void
    {
        $existe = $cuenta !== null && $seccion->origenes()
            ->where('nube_cuenta_id', $cuenta->id)
            ->where('remoto_item_id', $remoto->itemId)
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages(['enlace' => __('Esta carpeta o archivo ya está vinculado a la sección.')]);
        }
    }

    /**
     * Sin enlaces ni tokens en la bitácora: solo qué pasó y con qué origen.
     *
     * @param  array<string, mixed>  $extra
     */
    private function registrar(string $evento, SeccionOrigen $origen, User $usuario, string $mensaje, array $extra = []): void
    {
        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($origen)
            ->event($evento)
            ->withProperties(['seccion_id' => $origen->seccion_id, 'proveedor' => $origen->proveedor, 'nombre' => $origen->nombre] + $extra)
            ->log($mensaje);
    }
}
