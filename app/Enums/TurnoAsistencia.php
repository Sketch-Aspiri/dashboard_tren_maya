<?php

namespace App\Enums;

/**
 * Turno de una persona en un día de asistencia. Es el mismo dato que la columna "Turno" del Excel de control:
 * la coordinación revisa en la mañana que solo se escriban en la hoja de Google quienes están en el primer turno.
 */
enum TurnoAsistencia: string
{
    case Primer = 'primer';
    case Segundo = 'segundo';

    public function label(): string
    {
        return match ($this) {
            self::Primer => 'Primer turno',
            self::Segundo => 'Segundo turno',
        };
    }

    /** Como lo escribe el Excel de control. */
    public function etiquetaOficio(): string
    {
        return match ($this) {
            self::Primer => 'PRIMER TURNO',
            self::Segundo => 'SEGUNDO TURNO',
        };
    }
}
