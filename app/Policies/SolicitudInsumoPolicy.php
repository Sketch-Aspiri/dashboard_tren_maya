<?php

namespace App\Policies;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\User;
use App\Support\Roles;

/**
 * Autorización de "Recursos materiales" -> Solicitud de insumos. "Estación"
 * captura/envía solo la de su estación y solo mientras está en borrador u
 * observada; "Jefe de Zona", "Administrador" y "Edificio" ven todas; solo Jefe de Zona
 * y Administrador revisan, aprueban y consolidan.
 */
class SolicitudInsumoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([...Roles::ZONA, Roles::ESTACION]);
    }

    /**
     * Whether the user may open/start a solicitud of the given estación.
     */
    public function manageFor(User $user, Estacion $estacion): bool
    {
        return $user->hasAnyRole(Roles::ZONA)
            || ($user->hasRole(Roles::ESTACION) && $user->estacion_id === $estacion->id);
    }

    public function view(User $user, SolicitudInsumo $solicitud): bool
    {
        return $this->manageFor($user, $solicitud->estacion);
    }

    /**
     * La estación edita (cantidades, unidades, descripciones) y envía solo
     * en borrador u observada.
     */
    public function update(User $user, SolicitudInsumo $solicitud): bool
    {
        return $user->hasRole(Roles::ESTACION)
            && $user->estacion_id === $solicitud->estacion_id
            && $solicitud->esEditablePorEstacion();
    }

    /**
     * Revisión de la Zona: completa precios/partida, devuelve o aprueba.
     */
    public function review(User $user, SolicitudInsumo $solicitud): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA)
            && in_array($solicitud->estado, [SolicitudInsumo::ENVIADA, SolicitudInsumo::OBSERVADA], true);
    }

    /**
     * Consolidado y catálogo: solo la Zona.
     */
    public function consolidate(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }
}
