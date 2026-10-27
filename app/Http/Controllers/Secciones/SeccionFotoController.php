<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Models\Seccion;
use App\Services\Secciones\FotoService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sirve las fotos de las columnas de tipo Foto desde el disco privado, solo a
 * quien puede ver la sección. El nombre es el SHA-1 del contenido (la ruta
 * lo restringe a ese formato, sin separadores de directorio).
 */
class SeccionFotoController extends Controller
{
    public function __construct(private readonly FotoService $fotos) {}

    public function show(Seccion $seccion, string $foto): StreamedResponse
    {
        $this->authorize('view', $seccion);

        abort_unless($this->fotos->existe($foto), 404);

        return Storage::disk(config('secciones.disco'))->response($this->fotos->ruta($foto), null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
