<?php

namespace App\Services\Documentos;

use DomainException;
use Illuminate\Http\UploadedFile;

/**
 * Turns the signature image uploaded with an oficio (PNG or JPG) into a
 * bounded, re-encoded PNG in a private temporary file. Re-encoding through GD
 * guarantees what lands in the .docx is a plain raster image (no metadata,
 * no embedded payloads) and keeps transparency.
 */
final class ImportadorFirma
{
    /**
     * @return string the destination path (a PNG)
     *
     * @throws DomainException when the file is not a usable image
     */
    public function aPng(UploadedFile $archivo, string $destino): string
    {
        $ruta = $archivo->getRealPath();
        $medidas = $ruta !== false ? @getimagesize($ruta) : false;
        $limites = config('asistencia_documentos.firma');

        if ($medidas === false || ! in_array($medidas[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new DomainException(__('La firma debe ser una imagen PNG o JPG.'));
        }

        // Checked before decoding: a few KB of PNG can expand to gigabytes of pixels.
        if ($medidas[0] > $limites['max_dimension_origen_px'] || $medidas[1] > $limites['max_dimension_origen_px']) {
            throw new DomainException(__('La imagen de la firma es demasiado grande.'));
        }

        $origen = @imagecreatefromstring((string) file_get_contents($ruta));

        if ($origen === false) {
            throw new DomainException(__('No se pudo leer la imagen de la firma.'));
        }

        $escala = min(1, $limites['max_lado_px'] / max($medidas[0], $medidas[1]));
        $ancho = max(1, (int) round($medidas[0] * $escala));
        $alto = max(1, (int) round($medidas[1] * $escala));

        $lienzo = imagecreatetruecolor($ancho, $alto);
        imagealphablending($lienzo, false);
        imagesavealpha($lienzo, true);
        imagefill($lienzo, 0, 0, imagecolorallocatealpha($lienzo, 255, 255, 255, 127));
        imagecopyresampled($lienzo, $origen, 0, 0, 0, 0, $ancho, $alto, $medidas[0], $medidas[1]);

        $guardado = imagepng($lienzo, $destino);
        @chmod($destino, 0600);

        if (! $guardado) {
            throw new DomainException(__('No se pudo procesar la imagen de la firma.'));
        }

        return $destino;
    }
}
