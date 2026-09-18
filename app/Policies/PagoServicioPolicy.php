<?php

namespace App\Policies;

use App\Models\PagoServicio;
use App\Models\User;

/**
 * Only "Administrador" may delete a captured payment, to correct
 * after-the-fact mistakes without allowing accidental data loss from every
 * role that can write (same rule as EstadisticaDiariaPolicy::delete()).
 */
class PagoServicioPolicy
{
    public function delete(User $user, PagoServicio $pago): bool
    {
        return $user->hasRole('Administrador');
    }
}
