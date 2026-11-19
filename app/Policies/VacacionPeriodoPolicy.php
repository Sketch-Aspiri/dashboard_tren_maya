<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Rol de vacaciones: lo sube, autoriza y descarga la Zona (Jefe de Zona, Administrador y Edificio).
 * Las estaciones ven el suyo y piden cambios; nunca lo modifican sin que la Zona autorice.
 */
class VacacionPeriodoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    /** Subir el rol, editar directo, resolver solicitudes y descargar el consolidado. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }

    /** Proponer cambios: la estación con estación asignada. */
    public function solicitar(User $user): bool
    {
        return $user->hasRole(Roles::ESTACION) && $user->estacion_id !== null;
    }
}
