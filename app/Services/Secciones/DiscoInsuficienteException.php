<?php

namespace App\Services\Secciones;

use RuntimeException;

/**
 * No queda la reserva de disco configurada: la sincronización se detiene
 * antes de descargar más. El mensaje es apto para mostrarse al usuario.
 */
final class DiscoInsuficienteException extends RuntimeException {}
