<?php

namespace App\Policies;

use App\Models\HorarioSolicitud;
use App\Models\User;
use App\Support\Roles;

/**
 * La estación ve y cancela las suyas (mientras estén pendientes); la Zona ve
 * todas y es la única que autoriza o rechaza.
 */
class HorarioSolicitudPolicy
{
    public function view(User $user, HorarioSolicitud $solicitud): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $solicitud->estacion_id);
    }

    public function cancelar(User $user, HorarioSolicitud $solicitud): bool
    {
        return $user->hasRole(Roles::ESTACION)
            && $user->estacion_id === $solicitud->estacion_id
            && $solicitud->esPendiente();
    }

    public function resolver(User $user, HorarioSolicitud $solicitud): bool
    {
        return $user->hasAnyRole(Roles::ZONA) && $solicitud->esPendiente();
    }
}
