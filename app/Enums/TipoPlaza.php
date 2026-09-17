<?php

namespace App\Enums;

/**
 * Type of position an Empleado holds. "Militar" and "Permanente" come from
 * the "Base de Datos Zona Oriente" sheet's roster; "Eventual" comes from
 * the "Cuadrillas de Mantenimiento" sheet (see
 * app/Console/Commands/ImportCuadrillasMantenimientoCommand.php).
 */
enum TipoPlaza: string
{
    case Militar = 'militar';
    case Permanente = 'permanente';
    case Eventual = 'eventual';

    public function label(): string
    {
        return match ($this) {
            self::Militar => 'Militar',
            self::Permanente => 'Permanente',
            self::Eventual => 'Eventual',
        };
    }
}
