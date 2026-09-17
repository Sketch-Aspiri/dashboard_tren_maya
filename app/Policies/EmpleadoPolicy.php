<?php

namespace App\Policies;

use App\Models\Empleado;
use App\Models\User;

/**
 * Authorization for the "Agenda Zona Oriente" -> Personal directory.
 * Both project roles may read; only "Administrador" may mutate this
 * real (PII-bearing) data.
 */
class EmpleadoPolicy
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
    public function view(User $user, Empleado $empleado): bool
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
    public function update(User $user, Empleado $empleado): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Empleado $empleado): bool
    {
        return $user->hasAnyRole(self::WRITE_ROLES);
    }
}
