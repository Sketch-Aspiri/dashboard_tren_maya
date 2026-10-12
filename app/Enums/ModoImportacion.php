<?php

namespace App\Enums;

/**
 * Cómo se aplica un documento arrastrado sobre la tabla de una sección
 * (SeccionImportacion). El usuario lo elige en la vista previa.
 */
enum ModoImportacion: string
{
    /** Todas las filas del documento se suman a las existentes. */
    case Agregar = 'agregar';

    /** Coincide por una columna llave: actualiza las que existen y agrega las nuevas; no borra nada. */
    case Actualizar = 'actualizar';

    /** El documento sustituye todo el contenido de la tabla. */
    case Reemplazar = 'reemplazar';

    public function label(): string
    {
        return match ($this) {
            self::Agregar => 'Agregar filas',
            self::Actualizar => 'Actualizar por columna llave',
            self::Reemplazar => 'Reemplazar toda la tabla',
        };
    }
}
