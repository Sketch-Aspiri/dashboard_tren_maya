<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use App\Models\RegistroDiario;
use Carbon\CarbonInterface;

/**
 * One person's line in an attendance oficio, already resolved to plain
 * values so templates/generators never touch Eloquent.
 */
final readonly class FilaPersonal
{
    public function __construct(
        public string $noEmpleado,
        public string $nombre,
        public ?TipoPlaza $tipoPlaza,
        public EstatusAsistencia $estatus,
        public ?CarbonInterface $fechaInicio,
        public ?CarbonInterface $fechaFin,
        public ?string $notas,
        public string $ubicacion,
    ) {}

    /**
     * A missing RegistroDiario counts as Presente — same default the
     * capture screen applies to anyone without a row yet.
     */
    public static function desde(Empleado $empleado, ?RegistroDiario $registro, string $ubicacion): self
    {
        return new self(
            noEmpleado: (string) $empleado->no_empleado,
            nombre: (string) $empleado->nombre_completo,
            tipoPlaza: $empleado->tipo_plaza,
            estatus: $registro?->estatus ?? EstatusAsistencia::Presente,
            fechaInicio: $registro?->fecha_inicio,
            fechaFin: $registro?->fecha_fin,
            notas: $registro?->notas,
            ubicacion: $ubicacion,
        );
    }

    /**
     * Status text as printed in the station oficio: "PRESENTE EN ESTACIÓN
     * PUERTO MORELOS", "DESCANSO", "VACACIONES (07 AL 19 DE SEPTIEMBRE)".
     */
    public function estatusTexto(bool $enEstacion): string
    {
        if ($this->estatus === EstatusAsistencia::Presente) {
            return $enEstacion ? 'PRESENTE EN ESTACIÓN '.mb_strtoupper($this->ubicacion) : 'PRESENTE';
        }

        $texto = mb_strtoupper($this->estatus->label());

        if ($this->notas !== null && trim($this->notas) !== '') {
            $texto .= ' '.mb_strtoupper(trim($this->notas));
        }

        $rango = FormatoFechaOficio::rangoLargo($this->fechaInicio, $this->fechaFin);

        return $rango === null ? $texto : $texto.' ('.$rango.')';
    }

    /**
     * Status text as printed in the zone oficio: "PRESENTE", "DESCANSO",
     * "VACACIONES 14 AL 21 SEP. 2026", "COMISIÓN CORP. MÉRIDA 17 AL 18 SEP. 2026".
     */
    public function estatusTextoZona(): string
    {
        if ($this->estatus === EstatusAsistencia::Presente) {
            return 'PRESENTE';
        }

        $texto = mb_strtoupper($this->estatus->label());

        if ($this->notas !== null && trim($this->notas) !== '') {
            $texto .= ' '.mb_strtoupper(trim($this->notas));
        }

        $rango = FormatoFechaOficio::rangoCorto($this->fechaInicio, $this->fechaFin);

        return $rango === null ? $texto : $texto.' '.$rango;
    }
}
