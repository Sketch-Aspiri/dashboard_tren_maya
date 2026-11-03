<?php

namespace App\Support;

use App\Models\User;

/**
 * ¿El usuario ve todo (roles de zona) o queda restringido a una estación?
 * Qué secciones y hojas abre una estación lo decide VisibilidadSecciones
 * (permisos por sección/hoja); esto solo identifica al usuario. Cualquier
 * caso sin rol de zona ni estación no ve nada (falla cerrada).
 */
final class AlcanceEstacion
{
    public static function esTotal(User $usuario): bool
    {
        return $usuario->hasAnyRole(Roles::ZONA);
    }

    /**
     * Id de la estación a la que queda restringido el usuario; null si no
     * está restringido (zona) o no tiene estación (no ve nada).
     */
    public static function estacionId(User $usuario): ?int
    {
        if (self::esTotal($usuario)) {
            return null;
        }

        return $usuario->hasRole(Roles::ESTACION) ? $usuario->estacion_id : null;
    }
}
