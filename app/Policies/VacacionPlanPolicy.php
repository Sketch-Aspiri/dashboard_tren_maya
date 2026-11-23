<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VacacionPlan;
use App\Support\Roles;

/**
 * Selección de vacaciones del personal. La persona solo toca la suya; la Zona revisa y configura el
 * catálogo; la estación solo mira el avance de su gente.
 */
class VacacionPlanPolicy
{
    /** Elegir, enviar y retirar la propia selección: cualquier cuenta ligada a una ficha de empleado, sea cual sea su rol. */
    public function propio(User $user): bool
    {
        return $user->empleado_id !== null;
    }

    /** Ver la bandeja de revisión (la estación solo ve su estación). */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    /** Autorizar, rechazar, ajustar días y administrar el catálogo y los topes. */
    public function gestionar(User $user): bool
    {
        return $user->hasAnyRole(Roles::ZONA);
    }

    public function ver(User $user, VacacionPlan $plan): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id !== null && $user->estacion_id === $plan->estacion_id);
    }
}
