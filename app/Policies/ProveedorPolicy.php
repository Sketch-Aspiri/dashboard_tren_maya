<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Proveedores e IVA del fondo rotatorio: los mantiene la jefatura
 * ("Jefe de Zona" y "Administrador"). Las estaciones no los ven.
 */
class ProveedorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }
}
