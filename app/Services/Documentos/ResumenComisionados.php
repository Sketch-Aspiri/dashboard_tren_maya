<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;

/**
 * The "comisionados" summary table (d) of the oficio. Like the Excel, each
 * person counts once, under their own daily status; only when that status is
 * "Comisión" the column depends on the kind of commission (permanente,
 * eventual or another coordination).
 */
final readonly class ResumenComisionados
{
    /**
     * @param  array<string, int>  $otrosEstatus  keyed by EstatusAsistencia value (never Comisión)
     */
    public function __construct(
        public int $comisionPermanente,
        public int $comisionEventual,
        public int $comisionOtraCoordinacion,
        public array $otrosEstatus,
        public int $total,
    ) {}

    public function de(EstatusAsistencia $estatus): int
    {
        return $this->otrosEstatus[$estatus->value] ?? 0;
    }
}
