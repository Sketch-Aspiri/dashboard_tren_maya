<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Services\Secciones\TablaService;
use Illuminate\Http\RedirectResponse;

/**
 * Baja manual de una hoja (tabla) de una sección.
 */
class SeccionTablaController extends Controller
{
    public function __construct(private readonly TablaService $tablas) {}

    public function destroy(Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $nombre = $tabla->nombre;
        $this->tablas->eliminar($tabla);

        return redirect()->route('secciones.show', $seccion)
            ->with('status', __('Hoja ":nombre" eliminada.', ['nombre' => $nombre]));
    }
}
