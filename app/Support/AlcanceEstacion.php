<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué filas/documentos puede ver un usuario según su estación.
 * Roles de zona: todo (incluido lo sin etiqueta). Estación: solo lo etiquetado
 * con su estación. Cualquier otro caso (sin rol, o Estación sin estación):
 * nada (falla cerrada).
 */
final class AlcanceEstacion
{
    public static function esTotal(User $usuario): bool
    {
        return $usuario->hasAnyRole(Roles::ZONA);
    }

    /**
     * Id de la estación a la que queda restringido el usuario; null si no
     * está restringido (zona) o no tiene estación (ver aplicar()).
     */
    public static function estacionId(User $usuario): ?int
    {
        if (self::esTotal($usuario)) {
            return null;
        }

        return $usuario->hasRole(Roles::ESTACION) ? $usuario->estacion_id : null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $consulta
     * @return Builder<TModel>
     */
    public static function aplicar(Builder $consulta, User $usuario, string $columna): Builder
    {
        if (self::esTotal($usuario)) {
            return $consulta;
        }

        $estacionId = self::estacionId($usuario);

        return $estacionId === null
            ? $consulta->whereRaw('1 = 0')
            : $consulta->where($columna, $estacionId);
    }
}
