<?php

namespace App\Enums;

/**
 * Daily attendance status for a RegistroDiario row. The 10 values and their
 * order are confirmed against the real "oficio" reference document (see
 * the approved plan) — do not reorder or add values without re-checking
 * that document.
 */
enum EstatusAsistencia: string
{
    case Presente = 'presente';
    case Descanso = 'descanso';
    case DiaNoLaborable = 'dia_no_laborable';
    case LicenciaMedica = 'licencia_medica';
    case Comision = 'comision';
    case AvisoIncidencia = 'aviso_incidencia';
    case ReporteAusentismo = 'reporte_ausentismo';
    case Vacaciones = 'vacaciones';
    case Baja = 'baja';
    case NuevoIngreso = 'nuevo_ingreso';

    public function label(): string
    {
        return match ($this) {
            self::Presente => 'Presente',
            self::Descanso => 'Descanso',
            self::DiaNoLaborable => 'Día no laborable',
            self::LicenciaMedica => 'Licencia médica',
            self::Comision => 'Comisión',
            self::AvisoIncidencia => 'Aviso de incidencia',
            self::ReporteAusentismo => 'Reporte de ausentismo',
            self::Vacaciones => 'Vacaciones',
            self::Baja => 'Baja',
            self::NuevoIngreso => 'Nuevo ingreso',
        };
    }
}
