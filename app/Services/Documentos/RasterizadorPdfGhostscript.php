<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Rasteriza con Ghostscript (en el VPS, `gs`): argumentos como arreglo, sin
 * shell y con -dSAFER para que un PDF ajeno no lea ni escriba otros archivos.
 */
final class RasterizadorPdfGhostscript implements RasterizadorPdf
{
    public function __construct(
        private readonly string $binario = 'gs',
        private readonly int $dpi = 150,
        private readonly int $timeoutSegundos = 55,
    ) {}

    public function paginas(string $pdf, string $prefijo, int $maxPaginas): array
    {
        $proceso = new Process(
            [
                $this->binario, '-dBATCH', '-dNOPAUSE', '-dQUIET', '-dSAFER',
                '-sDEVICE=jpeg', '-dJPEGQ=85', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                '-r'.$this->dpi, '-dFirstPage=1', '-dLastPage='.$maxPaginas,
                '-sOutputFile='.$prefijo.'-%03d.jpg', $pdf,
            ],
            null,
            ['PATH' => (string) getenv('PATH')],
            null,
            $this->timeoutSegundos,
        );
        $proceso->run();

        $paginas = glob($prefijo.'-*.jpg') ?: [];
        sort($paginas);

        if (! $proceso->isSuccessful() || $paginas === []) {
            Log::error('Ghostscript no pudo convertir el oficio de comisión.', ['salida' => mb_substr($proceso->getErrorOutput(), 0, 500)]);
            throw new ConversionPdfException('No se pudo leer un oficio de comisión (revisa que el archivo no esté dañado).');
        }

        return $paginas;
    }
}
