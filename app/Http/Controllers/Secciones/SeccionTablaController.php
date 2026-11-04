<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\Hoja\GuardarHojaRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Services\Secciones\TablaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Hojas (tablas) de una sección: alta, renombrar, mover, duplicar y baja.
 * Es estructura: solo roles de zona (update); una hoja espejo de la nube no
 * se renombra ni se borra a mano (modificarTabla), pero sí se puede duplicar
 * como hoja normal.
 */
class SeccionTablaController extends Controller
{
    public function __construct(private readonly TablaService $tablas) {}

    public function store(GuardarHojaRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('update', $seccion);
        abort_if($seccion->solo_documentos, 404);

        $tabla = $this->tablas->crear($seccion, $request->nombre());

        return $this->aLaHoja($seccion, $tabla, __('Hoja ":nombre" creada.', ['nombre' => $tabla->nombre]));
    }

    public function update(GuardarHojaRequest $request, Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('update', $seccion);
        $this->authorize('modificarTabla', [$seccion, $tabla]);

        $this->tablas->renombrar($tabla, $request->nombre());

        return $this->aLaHoja($seccion, $tabla, __('Hoja renombrada.'));
    }

    public function mover(MoverElementoRequest $request, Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->tablas->mover($tabla, $request->validated('direccion'));

        return $this->aLaHoja($seccion, $tabla);
    }

    public function duplicar(Request $request, Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $nombre = __(':nombre (copia)', ['nombre' => Str::limit($tabla->nombre, 150, '')]);
        $copia = $this->tablas->duplicar($tabla, $nombre, $request->user());

        return $this->aLaHoja($seccion, $copia, __('Hoja duplicada como ":nombre".', ['nombre' => $copia->nombre]));
    }

    public function destroy(Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('update', $seccion);
        $this->authorize('modificarTabla', [$seccion, $tabla]);

        $nombre = $tabla->nombre;
        $this->tablas->eliminar($tabla);

        return redirect()->route('secciones.show', $seccion)
            ->with('status', __('Hoja ":nombre" eliminada.', ['nombre' => $nombre]));
    }

    private function aLaHoja(Seccion $seccion, SeccionTabla $tabla, ?string $mensaje = null): RedirectResponse
    {
        return redirect()->route('secciones.show', [$seccion, 'tabla' => $tabla->id, 'tab' => 'tabla'])->with('status', $mensaje);
    }
}
