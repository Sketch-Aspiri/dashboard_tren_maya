<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\User;

/**
 * Autorización para el módulo "Controles" -> Escaleras eléctricas. Mismo
 * criterio que EstadisticaDiariaPolicy: "Jefe de Zona" y "Administrador"
 * ven y editan todas las estaciones; "Estación" solo la suya.
 */
class EscaleraElectricaPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Whether the user may edit escaleras eléctricas for the given
     * estación. "Administrador" and "Jefe de Zona" may manage any
     * estación; "Estación" may only manage its own.
     */
    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }
}
