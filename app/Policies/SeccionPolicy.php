<?php

namespace App\Policies;

use App\Models\Seccion;
use App\Models\User;

/**
 * Secciones dinámicas (tabla + documentos). Hoy solo existe la cuenta
 * compartida de administrador (Jefe de Zona + Administrador); el rol
 * "Estación" nunca entra a estas secciones.
 *
 * - view: ver la tabla y los documentos.
 * - gestionarDatos: capturar/editar/eliminar filas, subir documentos,
 *   importar y deshacer cargas.
 * - create/update/delete: administrar el menú (secciones y columnas).
 */
class SeccionPolicy
{
    private const ROLES = ['Jefe de Zona', 'Administrador'];

    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function view(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function create(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function update(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function delete(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public function gestionarDatos(User $user, Seccion $seccion): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }
}
