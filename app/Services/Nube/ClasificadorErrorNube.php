<?php

namespace App\Services\Nube;

use App\Enums\CodigoErrorNube;
use App\Models\NubeCuenta;
use App\Services\Secciones\NubeSyncException;

/**
 * Decide qué hacer ante un error de la nube a partir del código tipado de la
 * excepción (y del estado de la cuenta), no de su texto.
 */
final class ClasificadorErrorNube
{
    public function clasificar(NubeSyncException $e, ?NubeCuenta $cuenta): CodigoErrorNube
    {
        if ($cuenta !== null && ! $cuenta->fresh()?->estaActiva()) {
            return CodigoErrorNube::Reconexion;
        }

        return $e->codigo;
    }
}
