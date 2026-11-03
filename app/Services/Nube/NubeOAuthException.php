<?php

namespace App\Services\Nube;

use App\Enums\CodigoErrorNube;
use RuntimeException;
use Throwable;

/**
 * Fallo esperado del flujo OAuth (state inválido, autorización denegada,
 * intercambio o refresh rechazado). El mensaje es apto para mostrarse al
 * Administrador y NUNCA contiene tokens, secretos ni cuerpos de respuesta.
 * `codigo`: Reconexion = rechazo definitivo del proveedor; Transitorio =
 * timeout, 429 o 5xx (no cambia el estado de la cuenta).
 */
final class NubeOAuthException extends RuntimeException
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
