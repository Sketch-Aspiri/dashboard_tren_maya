<?php

namespace App\Services\Nube;

use RuntimeException;

/**
 * Fallo pasajero de la nube (429, 5xx, sin conexión): el job se reintenta con
 * espera creciente. El mensaje es apto para la bitácora (sin tokens ni URLs).
 */
final class NubeTransitoriaException extends RuntimeException {}
