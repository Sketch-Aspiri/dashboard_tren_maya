<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\CompartirSeccionRequest;
use App\Models\Seccion;
use App\Services\Secciones\AprobacionSeccionesService;
use Illuminate\Http\RedirectResponse;

/**
 * Qué estaciones ven una sección (aprobación del Jefe de Zona / Administrador).
 */
class SeccionEstacionController extends Controller
{
    public function __construct(private readonly AprobacionSeccionesService $aprobaciones) {}

    public function update(CompartirSeccionRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->aprobaciones->actualizar($seccion, $request->todas(), $request->estaciones(), $request->user());

        return back()->with('status', __('Aprobación de la sección actualizada.'));
    }
}
