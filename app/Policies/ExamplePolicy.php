<?php

namespace App\Policies;

use App\Models\Example;
use App\Models\User;

/**
 * Placeholder authorization for the disposable reference CRUD module (see
 * CLAUDE.md). Both project roles may read; only "Administrador" may
 * mutate. Adjust once the real module/roles for the CRUD data are defined
 * — this is intentionally simple, not the final access model.
 */
class ExamplePolicy
{
    private const READ_ROLES = ['Jefe de Zona', 'Administrador'];

    private const WRITE_ROLES = ['Administrador'];

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Example $example): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Example $example): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Example $example): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }
}
