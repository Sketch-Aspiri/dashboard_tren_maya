<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Une PDF con Ghostscript (en el VPS, `gs`). Argumentos como arreglo, sin
 * shell, y -dSAFER para que un PDF no pueda leer ni escribir otros archivos.
 */
final class UnidorPdfGhostscript implements UnidorPdf
{
    public function __construct(
        private readonly string $binario = 'gs',
        private readonly int $timeoutSegundos = 55,
    ) {}

    public function unir(array $origenes, string $destino): void
    {
        File::ensureDirectoryExists(dirname($destino));

        $proceso = new Process(
            [$this->binario, '-dBATCH', '-dNOPAUSE', '-dQUIET', '-dSAFER', '-sDEVICE=pdfwrite', '-dPDFSETTINGS=/prepress', '-sOutputFile='.$destino, ...$origenes],
            null,
            ['PATH' => (string) getenv('PATH')],
            null,
            $this->timeoutSegundos,
        );
        $proceso->run();

        if (! $proceso->isSuccessful() || ! is_file($destino)) {
            Log::error('Ghostscript no pudo unir los PDF.', ['salida' => mb_substr($proceso->getErrorOutput(), 0, 500)]);
            throw new ConversionPdfException('No se pudo armar el PDF de la comprobación.');
        }
    }
}
