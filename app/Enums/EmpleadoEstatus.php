<?php

namespace App\Enums;

/**
 * Status for a personnel directory ("Agenda Zona Oriente" -> Personal)
 * record: an active employee, or a vacant position on the roster (see
 * app/Console/Commands/ImportAgendaZonaOrienteCommand.php).
 */
enum EmpleadoEstatus: string
{
    case Activo = 'activo';
    case Vacante = 'vacante';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Vacante => 'Vacante',
        };
    }
}
