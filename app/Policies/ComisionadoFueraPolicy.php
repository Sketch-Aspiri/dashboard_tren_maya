<?php

namespace App\Policies;

use App\Models\ComisionadoFuera;
use App\Models\Estacion;
use App\Models\User;

/**
 * Authorization for "Personal de Zona Oriente comisionado fuera hoy"
 * (Etapa 2 — Control de Asistencia Diaria, see the approved plan). Same
 * shape as ComisionadoVisitantePolicy/RegistroDiarioPolicy.
 */
class ComisionadoFueraPolicy
{
    private const READ_ROLES = ['Jefe de Zona', 'Administrador', 'Estación'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::READ_ROLES);
    }

    public function view(User $user, ComisionadoFuera $comisionadoFuera): bool
    {
        return $user->hasAnyRole(['Jefe de Zona', 'Administrador'])
            || ($user->hasRole('Estación') && $user->estacion_id === $comisionadoFuera->estacion_id);
    }

    /**
     * Whether the user may create a comisionado fuera entry for the given
     * estación. There is no model instance yet at create time, so this
     * mirrors RegistroDiarioPolicy::captureFor()'s custom-ability shape
     * rather than the standard create(User) signature.
     */
    public function createFor(User $user, Estacion $estacion): bool
    {
        return $user->hasRole('Administrador')
            || ($user->hasRole('Estación') && $user->estacion_id === $estacion->id);
    }

    public function update(User $user, ComisionadoFuera $comisionadoFuera): bool
    {
        if ($user->hasRole('Administrador')) {
            return true;
        }

        return $user->hasRole('Estación')
            && $user->estacion_id === $comisionadoFuera->estacion_id
            && $comisionadoFuera->fecha->isToday();
    }

    public function delete(User $user, ComisionadoFuera $comisionadoFuera): bool
    {
        return $this->update($user, $comisionadoFuera);
    }
}
