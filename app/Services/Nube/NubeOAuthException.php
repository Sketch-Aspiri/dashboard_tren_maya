<?php

namespace App\Services\Nube;

use RuntimeException;

/**
 * Fallo esperado del flujo OAuth (state inválido, autorización denegada,
 * intercambio o refresh rechazado). El mensaje es apto para mostrarse al
 * Administrador y NUNCA contiene tokens, secretos ni cuerpos de respuesta.
 */
final class NubeOAuthException extends RuntimeException {}
