<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Models\User;

/**
 * Authorization for the "Estadísticas" module (flujo de pasajeros /
 * boletos vendidos por estación, ver el plan aprobado).
 *
 * Unlike RegistroDiarioPolicy, here "Jefe de Zona" both reads AND writes —
 * manageFor() is the single rule shared by view/create/update for a given
 * estación. Only "Administrador" may delete a captured day, to correct
 * after-the-fact mistakes without allowing accidental data loss from every
 * role that can write.
 */
class EstadisticaDiariaPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Whether the user may view/create/update estadísticas for the given
     * estación. "Administrador" and "Jefe de Zona" may manage any estación;
     * "Estación" may only manage its own.
     */
    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }

    public function delete(User $user, EstadisticaDiaria $registro): bool
    {
        return $user->hasRole('Administrador');
    }
}
