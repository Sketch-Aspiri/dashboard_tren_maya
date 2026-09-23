<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\User;

/**
 * Autorización para el módulo "Controles" -> Estatus de vías y andenes.
 * Mismo criterio que ElevadorPolicy (ver esa clase).
 */
class EstatusViaAndenPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Whether the user may edit vías/andenes for the given estación.
     * "Administrador" and "Jefe de Zona" may manage any estación;
     * "Estación" may only manage its own.
     */
    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }
}
