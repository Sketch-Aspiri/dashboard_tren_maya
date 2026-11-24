<?php

namespace App\Services\Documentos;

/**
 * Convierte un PDF en imágenes de página (una por hoja). Interfaz para que
 * las pruebas no dependan de Ghostscript.
 */
interface RasterizadorPdf
{
    /**
     * @param  string  $prefijo  ruta sin extensión: se crean "<prefijo>-001.jpg", "-002.jpg"…
     * @return list<string> rutas de las imágenes creadas, en orden de página
     *
     * @throws ConversionPdfException
     */
    public function paginas(string $pdf, string $prefijo, int $maxPaginas): array;
}
