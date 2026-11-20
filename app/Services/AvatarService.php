<?php

namespace App\Services;

use App\Jobs\EnviarFotoAlPortal;
use App\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Foto de perfil única del ecosistema (el portal es el origen). El archivo recibido NUNCA se guarda tal cual: se
 * decodifica con GD, se recorta al centro en cuadrado y se recodifica como JPEG (sin EXIF ni contenido incrustado).
 * Mismo comportamiento que AvatarService de Tickets y del portal.
 */
final class AvatarService
{
    public function actualizar(User $user, UploadedFile $archivo): User
    {
        return $this->guardarBytes($user, (string) file_get_contents($archivo->getRealPath()));
    }

    /**
     * Guarda la foto desde bytes JPG/PNG. `$propagar` es false cuando el cambio vino del portal (evita el eco).
     *
     * @throws InvalidArgumentException si no es una imagen válida
     */
    public function guardarBytes(User $user, string $bytes, bool $propagar = true): User
    {
        $jpeg = $this->normalizar($bytes);
        $disco = Storage::disk('local');
        $ruta = (string) config('ecosistema.avatares.directorio').'/'.Str::random(40).'.jpg';
        $anterior = $user->avatar_path;

        $disco->put($ruta, $jpeg);

        try {
            $user->forceFill(['avatar_path' => $ruta])->save();
        } catch (\Throwable $e) {
            $disco->delete($ruta);

            throw $e;
        }

        if ($anterior !== null && $anterior !== $ruta) {
            $disco->delete($anterior);
        }

        activity('perfil')->performedOn($user)->causedBy($propagar ? $user : null)->log('foto actualizada');

        if ($propagar) {
            EnviarFotoAlPortal::dispatch($user->id);
        }

        return $user;
    }

    public function quitar(User $user, bool $propagar = true): User
    {
        $anterior = $user->avatar_path;

        if ($anterior === null) {
            return $user;
        }

        $user->forceFill(['avatar_path' => null])->save();
        Storage::disk('local')->delete($anterior);

        activity('perfil')->performedOn($user)->causedBy($propagar ? $user : null)->log('foto retirada');

        if ($propagar) {
            EnviarFotoAlPortal::dispatch($user->id);
        }

        return $user;
    }

    /** Bytes del JPEG actual o null. */
    public function bytes(User $user): ?string
    {
        if ($user->avatar_path === null || ! Storage::disk('local')->exists($user->avatar_path)) {
            return null;
        }

        return (string) Storage::disk('local')->get($user->avatar_path);
    }

    /**
     * Devuelve los bytes del JPEG cuadrado ya reprocesado. Revisa las dimensiones ANTES de decodificar (una imagen
     * pequeña en bytes puede ocupar gigas en memoria al descomprimirse).
     */
    private function normalizar(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $max = (int) config('ecosistema.avatares.max_dimension');

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new InvalidArgumentException(__('La foto debe ser una imagen JPG o PNG.'));
        }

        if ($info[0] < 1 || $info[1] < 1 || $info[0] > $max || $info[1] > $max) {
            throw new InvalidArgumentException(__('La foto es demasiado grande (máximo :max px por lado).', ['max' => $max]));
        }

        $origen = @imagecreatefromstring($bytes);

        if (! $origen instanceof GdImage) {
            throw new InvalidArgumentException(__('La foto debe ser una imagen JPG o PNG.'));
        }

        $lado = (int) config('ecosistema.avatares.tamano');
        $ancho = imagesx($origen);
        $alto = imagesy($origen);
        $corte = min($ancho, $alto);

        $destino = imagecreatetruecolor($lado, $lado);
        // Fondo blanco: la transparencia de un PNG no se vuelve negra en el JPEG.
        imagefill($destino, 0, 0, (int) imagecolorallocate($destino, 255, 255, 255));
        imagecopyresampled($destino, $origen, 0, 0, intdiv($ancho - $corte, 2), intdiv($alto - $corte, 2), $lado, $lado, $corte, $corte);

        ob_start();
        imagejpeg($destino, null, 85);

        return (string) ob_get_clean();
    }
}
