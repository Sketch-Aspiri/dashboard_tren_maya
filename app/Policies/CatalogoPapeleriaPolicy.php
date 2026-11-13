<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Roles;

/**
 * Catálogo de papelería: lo mantiene solo la jefatura; las estaciones lo usan
 * únicamente como lista al capturar su solicitud.
 */
class CatalogoPapeleriaPolicy
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
