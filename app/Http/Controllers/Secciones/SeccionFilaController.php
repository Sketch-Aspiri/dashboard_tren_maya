<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarFilaRequest;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Services\Secciones\FilaService;
use Illuminate\Http\RedirectResponse;

/**
 * Alta, edición y baja manual de filas (formulario modal en la pantalla de
 * la sección).
 */
class SeccionFilaController extends Controller
{
    public function __construct(private readonly FilaService $filas) {}

    public function store(GuardarFilaRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('crearFila', $seccion);

        $this->filas->crear($request->tabla(), $request->valores(), $request->user(), $request->fotos(), $request->estacionElegida());

        return back()->with('status', __('Registro agregado.'));
    }

    public function update(GuardarFilaRequest $request, Seccion $seccion, SeccionFila $fila): RedirectResponse
    {
        $this->authorize('actualizarFila', [$seccion, $fila]);

        $this->filas->actualizar(
            $fila,
            $request->valores(),
            $request->user(),
            $request->fotos(),
            $request->estacionElegida(),
            $request->enviaEstacion(),
        );

        return back()->with('status', __('Registro actualizado.'));
    }

    public function destroy(Seccion $seccion, SeccionFila $fila): RedirectResponse
    {
        $this->authorize('eliminarFila', [$seccion, $fila]);

        $this->filas->eliminar($fila);

        return back()->with('status', __('Registro eliminado.'));
    }
}
