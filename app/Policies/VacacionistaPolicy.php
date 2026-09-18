<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for "Agenda Zona Oriente" -> Rol de vacaciones. Read-only
 * module: both project roles may read; "Estación" accounts never see it
 * (same audience as the Personal directory).
 */
class VacacionistaPolicy
{
    private const READ_ROLES = ['Jefe de Zona', 'Administrador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }
}
