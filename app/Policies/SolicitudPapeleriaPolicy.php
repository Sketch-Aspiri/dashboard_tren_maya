<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\SolicitudPapeleria;
use App\Models\User;
use App\Support\Roles;

/**
 * Autorización de "Recursos materiales" -> Solicitud de papelería. "Estación"
 * captura/envía solo la de su estación y solo en borrador u observada; la Zona
 * (Jefe de Zona, Administrador, Edificio) ve todas; solo la jefatura revisa,
 * descarga el Excel y mantiene el catálogo.
 */
class SolicitudPapeleriaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $estacion->id);
    }

    public function view(User $user, SolicitudPapeleria $solicitud): bool
    {
        return $this->manageFor($user, $solicitud->estacion);
    }

    public function update(User $user, SolicitudPapeleria $solicitud): bool
    {
        return $user->hasRole(Roles::ESTACION)
            && $user->estacion_id === $solicitud->estacion_id
            && $solicitud->esEditablePorEstacion();
    }

    public function review(User $user, SolicitudPapeleria $solicitud): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA) && $solicitud->estado === SolicitudPapeleria::ENVIADA;
    }

    /**
     * Descarga del Excel consolidado y mantenimiento del catálogo.
     */
    public function consolidate(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }
}
