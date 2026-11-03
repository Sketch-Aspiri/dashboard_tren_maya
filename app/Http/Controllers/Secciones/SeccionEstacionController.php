<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\CompartirSeccionRequest;
use App\Http\Requests\Secciones\CompartirTablaRequest;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Services\Secciones\AprobacionSeccionesService;
use Illuminate\Http\RedirectResponse;

/**
 * Permisos de las estaciones (Ver / Editar) sobre una sección completa o
 * sobre una hoja (solo Jefe de Zona / Administrador).
 */
class SeccionEstacionController extends Controller
{
    public function __construct(private readonly AprobacionSeccionesService $aprobaciones) {}

    public function update(CompartirSeccionRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('aprobarEstaciones', $seccion);

        $this->aprobaciones->actualizar($seccion, $request->todas(), $request->estaciones(), $request->user());

        return back()->with('status', __('Permisos de las estaciones actualizados.'));
    }

    public function updateTabla(CompartirTablaRequest $request, Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $this->authorize('aprobarEstaciones', $seccion);
        // Defensa en profundidad: scopeBindings ya impide una hoja de otra sección.
        abort_unless((int) $tabla->seccion_id === (int) $seccion->id, 404);

        $this->aprobaciones->actualizarTabla($tabla, $request->estaciones(), $request->user());

        return back()->with('status', __('Permisos de las estaciones sobre la hoja «:hoja» actualizados.', ['hoja' => $tabla->nombre]));
    }
}
