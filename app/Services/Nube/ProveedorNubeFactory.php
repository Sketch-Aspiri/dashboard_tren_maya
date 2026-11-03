<?php

namespace App\Services\Nube;

use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Models\SeccionOrigen;
use App\Services\Nube\Contracts\ProveedorNube as ContratoProveedorNube;
use App\Services\Nube\Proveedores\Dropbox;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\GoogleDrive;
use App\Services\Nube\Proveedores\MicrosoftGraph;

/**
 * Resuelve la implementación de nube de un origen: con cuenta conectada, la
 * del proveedor de esa cuenta; sin cuenta, el enlace público.
 */
final class ProveedorNubeFactory
{
    /**
     * Proveedor para inspeccionar un enlace antes de vincularlo: el de la
     * cuenta elegida, o el enlace público si no hay cuenta.
     */
    public function paraCuenta(?NubeCuenta $cuenta): ContratoProveedorNube
    {
        return $cuenta === null ? app(EnlacePublico::class) : $this->para($cuenta->proveedor);
    }

    public function para(SeccionOrigen|ProveedorNube $destino): ContratoProveedorNube
    {
        if ($destino instanceof SeccionOrigen) {
            $cuenta = $destino->cuenta;

            return $cuenta === null ? app(EnlacePublico::class) : $this->para($cuenta->proveedor);
        }

        return match ($destino) {
            ProveedorNube::Microsoft => app(MicrosoftGraph::class),
            ProveedorNube::Google => app(GoogleDrive::class),
            ProveedorNube::Dropbox => app(Dropbox::class),
        };
    }
}
