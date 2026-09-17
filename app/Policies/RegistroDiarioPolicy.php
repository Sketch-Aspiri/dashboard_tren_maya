<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Authorization for daily attendance capture (Fase 1 — Control de
 * Asistencia Diaria, see the approved plan).
 *
 * - All three project roles may read.
 * - Only "Administrador" and the owning "Estación" account may capture
 *   ("captureFor") — Jefe de Zona is read-only in this stage; their
 *   oversight view is a separate, later stage.
 * - "Administrador" may capture/update any estación (including the
 *   non-operational "Edificio Zonal Este") and any fecha, to correct
 *   after-the-fact errors. "Estación" may only capture/update its own
 *   estación, and only for today.
 */
class RegistroDiarioPolicy
{
    private const READ_ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function view(User $user, RegistroDiario $registroDiario): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $registroDiario->estacion_id);
    }

    /**
     * Whether the user may open/save the capture screen for the given
     * estación (which may be the non-operational "Edificio Zonal Este").
     */
    public function captureFor(User $user, Estacion $estacion): bool
    {
        return $user->hasRole('Administrador')
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }

    /**
     * Whether the user may save capture data for the given estación on the
     * given fecha. This is the single source of truth for the "Estación
     * may only write today, Administrador may write any date" rule
     * (decision #1 of the approved plan) — GuardarAsistenciaCapturaRequest
     * authorizes through this rather than re-implementing the date check.
     *
     * A bulk submission covers every roster row for one estación+fecha at
     * once, so this is checked once per submission rather than per row —
     * there is no standalone single-row RegistroDiario update ability.
     */
    public function captureForFecha(User $user, Estacion $estacion, CarbonInterface $fecha): bool
    {
        if (! $this->captureFor($user, $estacion)) {
            return false;
        }

        if ($user->hasRole('Administrador')) {
            return true;
        }

        return $fecha->isToday();
    }
}
