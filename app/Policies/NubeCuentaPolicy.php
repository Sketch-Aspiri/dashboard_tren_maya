<?php

namespace App\Policies;

use App\Models\NubeCuenta;
use App\Models\User;
use App\Support\Roles;

/**
 * "Conexiones de nube": ver las cuentas, cargar las credenciales OAuth,
 * conectar y desconectar cuentas. Solo el Administrador: los tokens dan
 * lectura sobre archivos de la institución.
 */
class NubeCuentaPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->esAdministrador($actor);
    }

    /** Conectar una cuenta nueva (inicia el OAuth). */
    public function create(User $actor): bool
    {
        return $this->esAdministrador($actor);
    }

    /** Cargar o cambiar el Client ID / Secret de un proveedor. */
    public function update(User $actor): bool
    {
        return $this->esAdministrador($actor);
    }

    public function delete(User $actor, NubeCuenta $cuenta): bool
    {
        return $this->esAdministrador($actor);
    }

    private function esAdministrador(User $actor): bool
    {
        return $actor->hasRole(Roles::ADMINISTRADOR);
    }
}
