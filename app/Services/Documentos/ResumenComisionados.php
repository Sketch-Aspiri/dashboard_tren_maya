<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;

/**
 * The "comisionados" summary table of the zone oficio. Unlike the other
 * three tables its columns do not partition the people: someone commissioned
 * out who is also on vacation shows up under both Comisión and Vacaciones,
 * while the total counts each person once — exactly how the real oficio
 * reads.
 */
final readonly class ResumenComisionados
{
    /**
     * @param  array<string, int>  $otrosEstatus  keyed by EstatusAsistencia value (never Presente/Comisión)
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
