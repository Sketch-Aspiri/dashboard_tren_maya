<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\TipoColumna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarColumnaRequest;
use App\Http\Requests\Secciones\ListarSeccionRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionTabla;
use App\Services\Secciones\ColumnaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SeccionColumnaController extends Controller
{
    public function __construct(private readonly ColumnaService $columnas) {}

    public function index(ListarSeccionRequest $request, Seccion $seccion): View
    {
        $this->authorize('update', $seccion);

        $tabla = $request->tabla();

        return view('secciones.columnas', [
            'seccion' => $seccion->load('grupo'),
            'tablaActiva' => $tabla,
            'columnas' => $tabla->columnas()->get(),
            'tiposColumna' => TipoColumna::cases(),
        ]);
    }

    public function store(GuardarColumnaRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $tabla = $request->tabla();
        $columna = $this->columnas->crear($tabla, $request->validated('nombre'), $request->tipo(), $request->opciones());

        return $this->volver($tabla, __('Columna ":nombre" agregada.', ['nombre' => $columna->nombre]));
    }

    public function update(GuardarColumnaRequest $request, Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->columnas->actualizar($columna, $request->validated('nombre'), $request->tipo(), $request->opciones());

        return $this->volver($columna->tabla, __('Columna actualizada.'));
    }

    public function mover(MoverElementoRequest $request, Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->columnas->mover($columna, $request->validated('direccion'));

        return redirect()->to(route('secciones.columnas.index', $this->parametrosTabla($columna->tabla)).'#columna-'.$columna->id);
    }

    public function destroy(Request $request, Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $tabla = $columna->tabla;
        $nombre = $columna->nombre;
        $this->columnas->eliminar($columna);

        if ($request->input('volver') === 'tabla') {
            return redirect()
                ->route('secciones.show', $this->parametrosTabla($tabla))
                ->with('status', __('Columna ":nombre" eliminada.', ['nombre' => $nombre]));
        }

        return $this->volver($tabla, __('Columna ":nombre" eliminada.', ['nombre' => $nombre]));
    }

    private function volver(SeccionTabla $tabla, ?string $mensaje = null): RedirectResponse
    {
        return redirect()->route('secciones.columnas.index', $this->parametrosTabla($tabla))->with('status', $mensaje);
    }

    /**
     * Ruta de la sección y, solo si tiene varias tablas, cuál se estaba editando.
     *
     * @return array<int|string, mixed>
     */
    private function parametrosTabla(SeccionTabla $tabla): array
    {
        return $tabla->seccion->tablas()->count() > 1 ? [$tabla->seccion, 'tabla' => $tabla->id] : [$tabla->seccion];
    }
}
