<?php

namespace App\Services\Secciones;

use Illuminate\Support\Facades\Storage;

/**
 * Fotos de las columnas de tipo Foto. Cada imagen se reduce a una miniatura
 * JPEG y se guarda en el disco privado con el SHA-1 de su contenido como
 * nombre (la misma foto en varias cargas no se duplica). En
 * seccion_filas.datos solo viaja ese nombre; se sirve por una ruta
 * autorizada (SeccionFotoController), nunca por URL pública.
 */
final class FotoService
{
    /** Prefijo con el que el extractor marca una celda que contiene una foto. */
    public const PREFIJO = 'foto:';

    public const PATRON_NOMBRE = '/^[a-f0-9]{40}\.jpg$/';

    private const DIRECTORIO = 'secciones/fotos';

    private const LADO_MAXIMO = 320;

    private const CALIDAD_JPEG = 82;

    /** Defensa contra imágenes que agotarían la memoria al decodificarse. */
    private const MAX_PIXELES = 40_000_000;

    public const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * Guarda la imagen y devuelve su nombre de archivo, o null si no es una
     * imagen legible.
     */
    public function guardar(string $contenido): ?string
    {
        if ($contenido === '' || strlen($contenido) > self::MAX_BYTES) {
            return null;
        }

        $miniatura = $this->miniatura($contenido);

        if ($miniatura === null) {
            return null;
        }

        $nombre = sha1($miniatura).'.jpg';
        $disco = Storage::disk(config('secciones.disco'));

        if (! $disco->exists(self::DIRECTORIO.'/'.$nombre)) {
            $disco->put(self::DIRECTORIO.'/'.$nombre, $miniatura);
        }

        return $nombre;
    }

    public function existe(string $nombre): bool
    {
        return preg_match(self::PATRON_NOMBRE, $nombre) === 1
            && Storage::disk(config('secciones.disco'))->exists(self::DIRECTORIO.'/'.$nombre);
    }

    public function ruta(string $nombre): string
    {
        return self::DIRECTORIO.'/'.$nombre;
    }

    /**
     * Nombre de archivo si el valor es una foto (con o sin el prefijo del
     * extractor); null si no.
     */
    public static function nombreDe(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $nombre = str_starts_with($valor, self::PREFIJO) ? substr($valor, strlen(self::PREFIJO)) : $valor;

        return preg_match(self::PATRON_NOMBRE, $nombre) === 1 ? $nombre : null;
    }

    private function miniatura(string $contenido): ?string
    {
        $info = @getimagesizefromstring($contenido);

        if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELES) {
            return null;
        }

        $origen = @imagecreatefromstring($contenido);

        if ($origen === false) {
            return null;
        }

        $escala = min(1, self::LADO_MAXIMO / max($info[0], $info[1]));
        $ancho = max(1, (int) round($info[0] * $escala));
        $alto = max(1, (int) round($info[1] * $escala));

        $destino = imagecreatetruecolor($ancho, $alto);
        // Fondo blanco: los PNG con transparencia no deben quedar negros en JPEG.
        imagefill($destino, 0, 0, (int) imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho, $alto, $info[0], $info[1]);

        ob_start();
        imagejpeg($destino, null, self::CALIDAD_JPEG);
        $jpeg = (string) ob_get_clean();

        return $jpeg === '' ? null : $jpeg;
    }
}
