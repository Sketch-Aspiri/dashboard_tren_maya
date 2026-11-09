<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Catálogo de insumos del fondo rotatorio: lo mantiene solo la jefatura
 * ("Jefe de Zona" y "Administrador"). Las estaciones lo usan únicamente como
 * autocompletado desde la captura de la solicitud.
 */
class CatalogoInsumoPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }

    public function manage(User $user): bool
    {
        return $user->hasAnyRole(Roles::JEFATURA);
    }
}
