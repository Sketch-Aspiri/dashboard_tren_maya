<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarFilaRequest;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Services\Secciones\FilaService;
use App\Services\Secciones\Hoja\EstructuraHojaService;
use Illuminate\Http\RedirectResponse;

/**
 * Alta, edición y baja manual de filas (formulario modal en la pantalla de
 * la sección).
 */
class SeccionFilaController extends Controller
{
    public function __construct(
        private readonly FilaService $filas,
        private readonly EstructuraHojaService $estructura,
    ) {}

    public function store(GuardarFilaRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('crearFila', [$seccion, $request->tabla()]);

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

        // Por la hoja de cálculo: así las fórmulas y celdas combinadas de abajo se recorren.
        $this->estructura->eliminarFilas($fila->tabla, [$fila->id]);

        return back()->with('status', __('Registro eliminado.'));
    }
}
