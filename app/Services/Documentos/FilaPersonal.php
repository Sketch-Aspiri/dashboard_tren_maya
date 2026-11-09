<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use App\Models\RegistroDiario;
use Carbon\CarbonInterface;

/**
 * One person's line in an attendance oficio, already resolved to plain
 * values so generators never touch Eloquent.
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
        /** Station managers (and above) print in bold, like the Excel. */
        public bool $destacada = false,
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
            destacada: self::esDirectivo($empleado->puesto),
        );
    }

    /** Station managers and above: bold in the oficio and first in their list. */
    public static function esDirectivo(?string $puesto): bool
    {
        return preg_match('/^(Gerencia de Estaci|Direcci)/iu', (string) $puesto) === 1;
    }

    /**
     * Status as the oficio prints it, one entry per printed line:
     * ["PRESENTE"], ["DESCANSO"], ["VACACIONES", "14 AL 21 SEP. 2026"],
     * ["COMISIÓN CORP. MÉRIDA", "17 AL 18 SEP. 2026"].
     *
     * @return list<string>
     */
    public function estatusLineas(): array
    {
        $texto = $this->estatus->etiquetaOficio();

        if ($this->estatus !== EstatusAsistencia::Presente && $this->notas !== null && trim($this->notas) !== '') {
            $texto .= ' '.mb_strtoupper(trim($this->notas));
        }

        $lineas = [$texto];
        $rango = $this->estatus === EstatusAsistencia::Presente
            ? null
            : FormatoFechaOficio::rangoCorto($this->fechaInicio, $this->fechaFin);

        if ($rango !== null) {
            $lineas[] = $rango;
        }

        return $lineas;
    }

    /** The same lines joined with a space ("VACACIONES 14 AL 21 SEP. 2026"). */
    public function estatusTexto(): string
    {
        return implode(' ', $this->estatusLineas());
    }
}
