<?php

namespace App\Services\Documentos;

use Illuminate\Support\Facades\File;

/**
 * Private scratch folder for the oficio files while they are being built and
 * converted. They hold personal data, so they must not live in the shared
 * system temp dir: this folder is inside git-ignored private storage, created
 * owner-only (0700), and anything older than an hour — what a killed PHP
 * process leaves behind — is purged every time the folder is used.
 */
final class DirectorioTemporal
{
    private const EDAD_MAXIMA_SEGUNDOS = 3600;

    public static function ruta(): string
    {
        $ruta = storage_path('app/private/tmp/documentos');

        if (! is_dir($ruta)) {
            // Another request may create it between the check and mkdir.
            @mkdir($ruta, 0700, true);
        }

        self::purgarObsoletos($ruta);

        return $ruta;
    }

    private static function purgarObsoletos(string $ruta): void
    {
        $limite = time() - self::EDAD_MAXIMA_SEGUNDOS;

        foreach (glob($ruta.DIRECTORY_SEPARATOR.'*') ?: [] as $entrada) {
            if ((filemtime($entrada) ?: $limite) >= $limite) {
                continue;
            }

            is_dir($entrada) ? File::deleteDirectory($entrada) : @unlink($entrada);
        }
    }
}
