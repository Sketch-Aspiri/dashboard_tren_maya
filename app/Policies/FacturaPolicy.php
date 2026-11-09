<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Facturas del fondo rotatorio: las cargan y revisan Jefe de Zona,
 * Administrador y Edificio. Las estaciones no las ven.
 */
class FacturaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
