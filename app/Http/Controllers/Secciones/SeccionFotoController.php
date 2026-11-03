<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Services\Secciones\FotoService;
use App\Support\AlcanceEstacion;
use Illuminate\Http\Request;
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

    public function show(Request $request, Seccion $seccion, string $foto): StreamedResponse
    {
        $this->authorize('view', $seccion);

        abort_unless($this->fotos->existe($foto), 404);

        // Las fotos se guardan por hash, sin dueño: una estación solo recibe la
        // que aparece en una de SUS filas de esta sección (el hash es [a-f0-9]{40}.jpg,
        // sin comodines que escapar en el LIKE).
        if (! AlcanceEstacion::esTotal($request->user())) {
            abort_unless(
                SeccionFila::query()->where('seccion_id', $seccion->id)->visiblePara($request->user())->where('datos', 'like', '%'.$foto.'%')->exists(),
                404,
            );
        }

        return Storage::disk(config('secciones.disco'))->response($this->fotos->ruta($foto), null, [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
