<?php

namespace App\Policies;

use App\Models\HorarioTurno;
use App\Models\User;
use App\Support\Roles;

/**
 * Cada estación ve y solicita solo sobre los turnos de su estación; la Zona ve
 * todos. Los turnos sin estación reconocida solo los ve la Zona.
 */
class HorarioTurnoPolicy
{
    public function view(User $user, HorarioTurno $turno): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $turno->estacion_id !== null && $user->estacion_id === $turno->estacion_id);
    }

    /**
     * Pedir un cambio: la estación dueña del turno (la Zona lo corrige directo
     * autorizando, no pidiendo).
     */
    public function solicitar(User $user, HorarioTurno $turno): bool
    {
        return $user->hasRole(Roles::ESTACION)
            && $turno->estacion_id !== null
            && $user->estacion_id === $turno->estacion_id;
    }
}
