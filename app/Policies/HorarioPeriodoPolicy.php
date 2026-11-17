<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Horario oficial: lo sube, lo autoriza y lo consolida la Zona (Jefe de Zona,
 * Administrador y Edificio). Las estaciones solo lo consultan (HorarioTurnoPolicy).
 */
class HorarioPeriodoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    /**
     * Subir el oficial, ver y descargar los consolidados.
     */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
