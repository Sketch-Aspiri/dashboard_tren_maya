<?php

namespace App\Policies;

use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use App\Models\User;

/**
 * Authorization for "Personal comisionado de otras coordinaciones presentes
 * hoy" (Etapa 2 — Control de Asistencia Diaria, see the approved plan).
 * Same shape as RegistroDiarioPolicy — see that class for the "who may
 * write this estación" reasoning. Unlike RegistroDiario, these entries are
 * incidental/optional per day, so they do get real create/update/delete
 * abilities.
 */
class ComisionadoVisitantePolicy
{
    private const READ_ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function view(User $user, ComisionadoVisitante $comisionadoVisitante): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $comisionadoVisitante->estacion_id);
    }

    /**
     * Whether the user may create a comisionado visitante entry for the
     * given estación. There is no model instance yet at create time, so
     * this mirrors RegistroDiarioPolicy::captureFor()'s custom-ability
     * shape rather than the standard create(User) signature.
     */
    public function createFor(User $user, Estacion $estacion): bool
    {
        return $user->hasRole('Administrador')
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }

    public function update(User $user, ComisionadoVisitante $comisionadoVisitante): bool
    {
        if ($user->hasRole('Administrador')) {
            return true;
        }

        return $user->hasRole('Estación')
            && $user->estacion_id === $comisionadoVisitante->estacion_id
            && $comisionadoVisitante->fecha->isToday();
    }

    public function delete(User $user, ComisionadoVisitante $comisionadoVisitante): bool
    {
        return $this->update($user, $comisionadoVisitante);
    }
}
