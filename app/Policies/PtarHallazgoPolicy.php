<?php

namespace App\Policies;

use App\Models\PtarHallazgo;
use App\Models\User;
use App\Support\Roles;

/**
 * Hallazgos de PTAR. La Zona (Jefe de Zona, Administrador y Edificio) los ve todos y los gestiona (alta, cambio de
 * estado, cierre, envío a Tickets, oficio). La estación solo consulta los de su planta.
 */
class PtarHallazgoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    public function view(User $user, PtarHallazgo $hallazgo): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $hallazgo->planta->estacion_id);
    }

    /** Alta, edición, cierre, envío a Tickets y oficio mensual: solo la Zona. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }
}
