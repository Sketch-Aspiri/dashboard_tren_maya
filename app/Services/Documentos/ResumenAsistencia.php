<?php

namespace App\Services\Documentos;

use App\Enums\EstatusAsistencia;

/**
 * Per-status head count for an attendance oficio. Always derived from the
 * same FilaPersonal list that is printed, so the summary table can never
 * disagree with the listing (the hand-made Word originals sometimes did).
 */
final readonly class ResumenAsistencia
{
    /**
     * @param  array<string, int>  $conteos  keyed by EstatusAsistencia value
     */
    private function __construct(private array $conteos) {}

    /**
     * @param  iterable<FilaPersonal>  $filas
     */
    public static function desdeFilas(iterable $filas): self
    {
        $conteos = array_fill_keys(array_map(fn (EstatusAsistencia $e) => $e->value, EstatusAsistencia::cases()), 0);

        foreach ($filas as $fila) {
            $conteos[$fila->estatus->value]++;
        }

        return new self($conteos);
    }

    public function de(EstatusAsistencia $estatus): int
    {
        return $this->conteos[$estatus->value];
    }

    public function total(): int
    {
        return array_sum($this->conteos);
    }
}
