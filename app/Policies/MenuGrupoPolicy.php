<?php

namespace App\Policies;

use App\Models\MenuGrupo;
use App\Models\User;

/**
 * Grupos del menú principal ("Administrar menú").
 */
class MenuGrupoPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function update(User $user, MenuGrupo $grupo): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function delete(User $user, MenuGrupo $grupo): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }
}
