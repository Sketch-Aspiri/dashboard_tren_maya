<?php

namespace App\Services\Documentos;

/**
 * Junta varios PDF en uno. Interfaz para que las pruebas no dependan de
 * Ghostscript.
 */
interface UnidorPdf
{
    /**
     * @param  list<string>  $origenes  rutas absolutas, en el orden en que irán
     *
     * @throws ConversionPdfException
     */
    public function unir(array $origenes, string $destino): void;
}
