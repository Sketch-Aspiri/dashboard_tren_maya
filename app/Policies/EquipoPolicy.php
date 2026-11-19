<?php

namespace App\Policies;

use App\Models\Equipo;
use App\Models\User;
use App\Support\Roles;

/** Equipos por estación y sus secciones: solo el Administrador. */
class EquipoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(Roles::ADMINISTRADOR);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Equipo $equipo): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, Equipo $equipo): bool
    {
        return $this->viewAny($user);
    }
}
