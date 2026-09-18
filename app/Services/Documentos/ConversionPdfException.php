<?php

namespace App\Services\Documentos;

use RuntimeException;

/**
 * The PDF conversion failed. The message is safe to show to a user; the
 * technical detail (stderr, paths) is logged server-side only.
 */
final class ConversionPdfException extends RuntimeException {}
