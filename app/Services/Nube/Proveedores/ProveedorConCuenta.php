<?php

namespace App\Services\Nube\Proveedores;

use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\ClienteNube;
use App\Services\Nube\Contracts\ProveedorNube;
use App\Services\Secciones\NubeSyncException;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Base de los proveedores que leen con una cuenta OAuth (Microsoft, Google,
 * Dropbox): resuelve la cuenta y comparte utilidades de rutas y fechas.
 */
abstract class ProveedorConCuenta implements ProveedorNube
{
    public function __construct(protected readonly ClienteNube $cliente) {}

    public function listar(SeccionOrigen $origen): array
    {
        return $this->listarConResumen($origen)->archivos;
    }

    protected function exigirCuenta(?NubeCuenta $cuenta): NubeCuenta
    {
        return $cuenta ?? throw new NubeSyncException(__('Este enlace necesita una cuenta de nube conectada.'));
    }

    protected function cuentaDelOrigen(SeccionOrigen $origen): NubeCuenta
    {
        return $origen->cuenta ?? throw new NubeSyncException(__('La cuenta de nube de este origen ya no está conectada.'));
    }

    protected function unirRuta(string $base, string $nombre): string
    {
        return $base === '' ? $nombre : $base.'/'.$nombre;
    }

    protected function fecha(mixed $valor): ?CarbonImmutable
    {
        if (! is_string($valor) || $valor === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor);
        } catch (Throwable) {
            return null;
        }
    }

    protected function incluyeSubcarpetas(SeccionOrigen $origen): bool
    {
        return (bool) ($origen->incluir_subcarpetas ?? true);
    }

    protected function maxProfundidad(): int
    {
        return (int) config('secciones.nube.max_profundidad');
    }
}
