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

    /** Cómo lo publica el SAT, en español, para cada valor de la librería. */
    private const CANCELABLE = [
        'cancellableByDirectCall' => 'Cancelable sin aceptación',
        'cancellableByApproval' => 'Cancelable con aceptación',
        'notCancellable' => 'No cancelable',
    ];

    private const CANCELACION = [
        'undefined' => 'Sin cancelación en proceso',
        'pending' => 'En proceso',
        'disapproved' => 'Solicitud rechazada',
        'cancelledByApproval' => 'Cancelado con aceptación',
        'cancelledByExpiration' => 'Cancelado por plazo vencido',
        'cancelledByDirectCall' => 'Cancelado sin aceptación',
    ];

    public static function textoCancelable(?string $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (self::CANCELABLE[$valor] ?? $valor);
    }

    public static function textoCancelacion(?string $valor): ?string
    {
        return $valor === null || $valor === '' ? null : (self::CANCELACION[$valor] ?? $valor);
    }

    public function __construct(
        public readonly string $estado,
        public readonly ?string $esCancelable = null,
        public readonly ?string $estatusCancelacion = null,
        public readonly ?string $detalle = null,
    ) {}
}
