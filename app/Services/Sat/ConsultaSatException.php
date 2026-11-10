<?php

namespace App\Services\Sat;

use RuntimeException;

/** El SAT no respondió, tardó demasiado o el CFDI no permite armar la consulta. */
final class ConsultaSatException extends RuntimeException {}
