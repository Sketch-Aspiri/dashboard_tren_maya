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

    /**
     * The status exactly as the oficio prints it (the Excel's own spelling:
     * no accents except "COMISIÓN").
     */
    public function etiquetaOficio(): string
    {
        return match ($this) {
            self::Presente => 'PRESENTE',
            self::Descanso => 'DESCANSO',
            self::DiaNoLaborable => 'DIA NO LABORABLE',
            self::LicenciaMedica => 'LICENCIA MEDICA',
            self::Comision => 'COMISIÓN',
            self::AvisoIncidencia => 'AVISO DE INCIDENCIA',
            self::ReporteAusentismo => 'REPORTE DE AUSENTISMO',
            self::Vacaciones => 'VACACIONES',
            self::Baja => 'BAJA',
            self::NuevoIngreso => 'NUEVO INGRESO',
        };
    }
}
