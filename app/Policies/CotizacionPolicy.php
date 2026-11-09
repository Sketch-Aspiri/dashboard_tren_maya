<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Cotizaciones del fondo rotatorio: las capturan y revisan Jefe de Zona,
 * Administrador y Edificio. Las estaciones no las ven.
 */
class CotizacionPolicy
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
