<?php

namespace App\Services\Secciones;

use App\Enums\CodigoErrorNube;
use RuntimeException;
use Throwable;

/**
 * Fallo esperado al sincronizar con la nube (enlace caído, archivo
 * incorrecto, demasiado grande...). El mensaje es apto para mostrarse al
 * usuario; `codigo` dice de qué clase es para que el motor decida sin
 * comparar textos.
 */
final class NubeSyncException extends RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly CodigoErrorNube $codigo = CodigoErrorNube::Otro,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
