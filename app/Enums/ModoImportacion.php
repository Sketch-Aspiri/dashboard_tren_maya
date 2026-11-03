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

    /**
     * Tabla espejo de la nube: siempre igual al Excel. Con llave actualiza las
     * filas que coinciden (también con celdas vacías), agrega las nuevas y
     * archiva las que ya no vienen; sin llave reemplaza todo. Solo lo usa la
     * sincronización: el usuario nunca lo elige.
     */
    case Espejo = 'espejo';

    /**
     * Los modos que el usuario puede elegir en la vista previa.
     *
     * @return list<self>
     */
    public static function manuales(): array
    {
        return [self::Agregar, self::Actualizar, self::Reemplazar];
    }

    public function label(): string
    {
        return match ($this) {
            self::Agregar => 'Agregar filas',
            self::Actualizar => 'Actualizar por columna llave',
            self::Reemplazar => 'Reemplazar toda la tabla',
            self::Espejo => 'Espejo de la nube',
        };
    }
}
