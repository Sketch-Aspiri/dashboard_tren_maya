<?php

namespace App\Policies;

use App\Models\HorarioPeriodo;
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
     * Editar directo (sin pedir autorización): solo la Zona, y solo mientras el
     * periodo sea el vigente o un borrador.
     */
    public function editar(User $user, HorarioTurno $turno): bool
    {
        $turno->loadMissing('periodo');

        return $user->hasAnyRole(Roles::ZONA)
            && in_array($turno->periodo->estado, [HorarioPeriodo::VIGENTE, HorarioPeriodo::BORRADOR], true);
    }

    /**
     * Pedir un cambio: la estación dueña del turno (la Zona edita directo,
     * no pide).
     */
    public function solicitar(User $user, HorarioTurno $turno): bool
    {
        return $user->hasRole(Roles::ESTACION)
            && $turno->estacion_id !== null
            && $user->estacion_id === $turno->estacion_id;
    }
}
