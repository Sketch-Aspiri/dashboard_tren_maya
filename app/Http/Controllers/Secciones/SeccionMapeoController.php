<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ActualizarMapeoRequest;
use App\Http\Requests\Secciones\RetenidoMapeoRequest;
use App\Models\Seccion;
use App\Models\SeccionOrigen;
use App\Services\Secciones\MapeoEspejoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Mapeo de las tablas espejo de un origen: editarlo y resolver los cambios
 * masivos retenidos (aplicar / ignorar). Solo quien gestiona los datos
 * (roles de zona, igual que el resto de las acciones de nube).
 */
class SeccionMapeoController extends Controller
{
    public function __construct(private readonly MapeoEspejoService $mapeos) {}

    public function edit(Seccion $seccion, SeccionOrigen $origen): View
    {
        $this->authorize('gestionarDatos', $seccion);

        return view('secciones.mapeo', [
            'seccion' => $seccion->load('grupo'),
            'origen' => $origen,
            'hojas' => $this->mapeos->paraEdicion($origen),
        ]);
    }

    public function update(ActualizarMapeoRequest $request, Seccion $seccion, SeccionOrigen $origen): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->mapeos->actualizarVarios($request->cambios(), $request->user(), $request->boolean('confirmar_masivo'));

        return redirect()->route('secciones.origenes.mapeo.edit', [$seccion, $origen])->with('status', __('Mapeo guardado y tablas actualizadas.'));
    }

    public function aplicarRetenido(RetenidoMapeoRequest $request, Seccion $seccion, SeccionOrigen $origen): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->mapeos->aplicarRetenido($request->mapeo(), $request->user());

        return back()->with('status', __('Cambio aplicado: la tabla quedó igual al Excel de la nube.'));
    }

    public function ignorarRetenido(RetenidoMapeoRequest $request, Seccion $seccion, SeccionOrigen $origen): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->mapeos->ignorarRetenido($request->mapeo(), $request->user());

        return back()->with('status', __('Cambio ignorado: la tabla conserva sus datos.'));
    }
}
