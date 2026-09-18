<?php

namespace App\Services\Documentos;

/**
 * Turns a generated .docx into a PDF. An interface so feature tests can
 * swap in a fake and never depend on LibreOffice being installed.
 */
interface ConvertidorPdf
{
    /**
     * @param  string  $docx  absolute path of the source .docx
     * @param  string  $pdfDestino  absolute path where the PDF must end up
     *
     * @throws ConversionPdfException
     */
    public function convertir(string $docx, string $pdfDestino): void;
}
