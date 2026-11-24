<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarHallazgosPtarRequest;
use App\Http\Requests\GuardarHallazgoPtarRequest;
use App\Models\PtarHallazgo;
use App\Services\Ecosistema\ActividadesEcosistema;
use App\Services\Ptar\PtarHallazgoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Hallazgos de PTAR: la Zona los da de alta, cambia su estado y los manda a Tickets; la estación consulta los suyos.
 */
class PtarHallazgoController extends Controller
{
    public function __construct(
        private readonly PtarHallazgoService $service,
        private readonly ActividadesEcosistema $actividades,
    ) {}

    public function index(ConsultarHallazgosPtarRequest $request): View
    {
        $usuario = $request->user();
        $estaciones = $usuario->hasRole('Estación') ? [(int) $usuario->estacion_id] : null;

        return view('ptar.hallazgos', [
            'filtros' => $request->validated() + ['estado' => 'activos'],
            'hallazgos' => $this->service->listar($request->validated(), $estaciones),
            'plantas' => $this->service->plantas($estaciones),
            'puedeGestionar' => $usuario->can('gestionar', PtarHallazgo::class),
            'ticketsDisponible' => $this->actividades->disponible('ptar_hallazgo'),
            'equipos' => config('ptar.equipos'),
        ]);
    }

    public function store(GuardarHallazgoPtarRequest $request): RedirectResponse
    {
        $this->service->crear($request->validated(), $request->user());

        return redirect()->route('ptar.hallazgos.index')->with('status', __('Hallazgo registrado.'));
    }

    public function update(PtarHallazgo $hallazgo, GuardarHallazgoPtarRequest $request): RedirectResponse
    {
        $this->service->actualizar($hallazgo, $request->validated(), $request->user());

        return redirect()->route('ptar.hallazgos.index', $request->only('estado', 'planta', 'tipo'))->with('status', __('Hallazgo actualizado.'));
    }

    public function tickets(PtarHallazgo $hallazgo, Request $request): RedirectResponse
    {
        $this->authorize('gestionar', PtarHallazgo::class);

        $this->service->enviarATickets($hallazgo, $request->user());

        return redirect()->route('ptar.hallazgos.index')->with('status', __('Se pidió la actividad a Tickets; el folio aparece en unos momentos.'));
    }
}
