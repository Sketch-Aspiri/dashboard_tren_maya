<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for "Estadísticas" -> Gasto energético. Zone-wide report:
 * only the zone roles read it ("Estación" accounts never see other
 * stations' payments), and — same split as the passenger statistics — both
 * zone roles may capture/correct it while deleting a payment is limited to
 * "Administrador" (PagoServicioPolicy::delete).
 */
class ServicioEstacionPolicy
{
    private const ZONA_ROLES = ['Jefe de Zona', 'Administrador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ZONA_ROLES);
    }

    /**
     * Capture or correct the servicios and monthly payments of any estación.
     */
    public function manage(User $user): bool
    {
        return $user->hasAnyRole(self::ZONA_ROLES);
    }
}
