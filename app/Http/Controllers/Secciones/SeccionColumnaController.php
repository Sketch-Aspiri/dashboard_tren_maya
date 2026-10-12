<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\TipoColumna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarColumnaRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Services\Secciones\ColumnaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class SeccionColumnaController extends Controller
{
    public function __construct(private readonly ColumnaService $columnas) {}

    public function index(Seccion $seccion): View
    {
        $this->authorize('update', $seccion);

        return view('secciones.columnas', [
            'seccion' => $seccion->load('grupo', 'columnas'),
            'tiposColumna' => TipoColumna::cases(),
        ]);
    }

    public function store(GuardarColumnaRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $columna = $this->columnas->crear($seccion, $request->validated('nombre'), $request->tipo(), $request->opciones());

        return $this->volver($seccion, __('Columna ":nombre" agregada.', ['nombre' => $columna->nombre]));
    }

    public function update(GuardarColumnaRequest $request, Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->columnas->actualizar($columna, $request->validated('nombre'), $request->tipo(), $request->opciones());

        return $this->volver($seccion, __('Columna actualizada.'));
    }

    public function mover(MoverElementoRequest $request, Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->columnas->mover($columna, $request->validated('direccion'));

        return $this->volver($seccion);
    }

    public function destroy(Seccion $seccion, SeccionColumna $columna): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->columnas->eliminar($columna);

        return $this->volver($seccion, __('Columna ":nombre" eliminada.', ['nombre' => $columna->nombre]));
    }

    private function volver(Seccion $seccion, ?string $mensaje = null): RedirectResponse
    {
        return redirect()->route('secciones.columnas.index', $seccion)->with('status', $mensaje);
    }
}
