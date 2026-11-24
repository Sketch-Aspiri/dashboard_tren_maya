<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\OficioComision;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Auth\Access\Response;

/**
 * Oficios de comisión: los roles de zona gestionan los de cualquier estación;
 * una cuenta "Estación" solo los de la suya. Lo demás se responde como 404.
 */
class OficioComisionPolicy
{
    public function gestionarDe(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $estacion->id);
    }

    public function view(User $user, OficioComision $oficio): Response
    {
        return $this->gestionarDe($user, $oficio->estacion) ? Response::allow() : Response::denyAsNotFound();
    }

    public function delete(User $user, OficioComision $oficio): Response
    {
        return $this->view($user, $oficio);
    }
}
