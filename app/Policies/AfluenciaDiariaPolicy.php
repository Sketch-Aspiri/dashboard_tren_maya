<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\User;
use App\Support\Roles;

/**
 * Flujo de pasajeros. La Zona (Jefe de Zona, Administrador y Edificio) ve y corrige todas las estaciones y
 * descarga el Excel; "Estación" solo captura y corrige la suya.
 */
class AfluenciaDiariaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $estacion->id);
    }

    /** Tablero consolidado y Excel: solo la Zona. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
