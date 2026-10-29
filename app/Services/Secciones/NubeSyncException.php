<?php

namespace App\Services\Secciones;

use RuntimeException;

/**
 * Fallo esperado al sincronizar con la nube (enlace caído, archivo
 * incorrecto, demasiado grande...). El mensaje es apto para mostrarse al
 * usuario.
 */
final class NubeSyncException extends RuntimeException {}
