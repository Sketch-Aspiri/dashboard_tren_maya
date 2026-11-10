<?php

namespace App\Services\Sat;

/**
 * Respuesta del SAT ya traducida: `estado` es VIGENTE, CANCELADO o
 * NO_ENCONTRADO; el resto es el texto que devolvió el servicio.
 */
final class ResultadoSat
{
    public const VIGENTE = 'VIGENTE';

    public const CANCELADO = 'CANCELADO';

    public const NO_ENCONTRADO = 'NO_ENCONTRADO';

    public function __construct(
        public readonly string $estado,
        public readonly ?string $esCancelable = null,
        public readonly ?string $estatusCancelacion = null,
        public readonly ?string $detalle = null,
    ) {}
}
