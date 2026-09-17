<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for the "Gestión de usuarios" panel — account
 * create/edit/delete, role assignment, and station assignment. Only
 * "Administrador" may access this module at all; "Jefe de Zona" and
 * "Estación" have no access (this module manages the accounts, including
 * their own).
 */
class UserPolicy
{
    private const MANAGE_ROLE = 'Administrador';

    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(self::MANAGE_ROLE);
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->hasRole(self::MANAGE_ROLE);
    }

    public function create(User $actor): bool
    {
        return $actor->hasRole(self::MANAGE_ROLE);
    }

    public function update(User $actor, User $target): bool
    {
        return $actor->hasRole(self::MANAGE_ROLE);
    }

    /**
     * Administradores may delete any account except their own — blocks the
     * classic self-lockout footgun. This is the single source of truth for
     * that rule; the controller/service assume it's already been enforced
     * here.
     */
    public function delete(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $actor->hasRole(self::MANAGE_ROLE);
    }
}
