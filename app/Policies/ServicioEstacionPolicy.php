<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\User;

/**
 * Authorization for "Estadísticas" -> Gasto energético. Same split as the
 * passenger statistics (EstadisticaDiariaPolicy): "Jefe de Zona" and
 * "Administrador" read/capture every estación; "Estación" reads/captures
 * only its own. Deleting a payment stays limited to "Administrador"
 * (PagoServicioPolicy::delete).
 */
class ServicioEstacionPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Whether the user may view/capture/correct the servicios and monthly
     * payments of the given estación. "Administrador" and "Jefe de Zona"
     * may manage any estación; "Estación" may only manage its own.
     */
    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }
}
