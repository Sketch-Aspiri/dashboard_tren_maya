<?php

namespace App\Enums;

/**
 * Permiso de una estación sobre una sección completa o sobre una hoja.
 * Ver: consulta la hoja completa (todas sus filas y documentos). Editar:
 * además agrega, modifica y borra filas y sube/borra documentos.
 */
enum NivelAccesoEstacion: string
{
    case Ver = 'ver';
    case Editar = 'editar';

    public function label(): string
    {
        return match ($this) {
            self::Ver => 'Ver',
            self::Editar => 'Editar',
        };
    }

    public function permiteEditar(): bool
    {
        return $this === self::Editar;
    }

    /**
     * El mayor de dos niveles (los permisos solo suman: nunca restan).
     */
    public static function mayor(?self $a, ?self $b): ?self
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a === self::Editar || $b === self::Editar ? self::Editar : self::Ver;
    }

    /**
     * @return list<string>
     */
    public static function valores(): array
    {
        return array_map(fn (self $nivel) => $nivel->value, self::cases());
    }
}
