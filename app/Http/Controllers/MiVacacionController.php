<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarSeleccionVacacionRequest;
use App\Models\VacacionCatalogoPeriodo;
use App\Models\VacacionPlan;
use App\Services\Vacaciones\SeleccionVacacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vista de la persona (rol Personal): elige un periodo por trimestre, ve el cupo de su estación y envía.
 */
class MiVacacionController extends Controller
{
    public function __construct(private readonly SeleccionVacacionService $service) {}

    public function show(Request $request): View
    {
        $this->authorize('propio', VacacionPlan::class);

        $anio = (int) config('vacaciones.anio_seleccion');
        $plan = $this->service->planDe($request->user()->empleado, $anio)->load('selecciones.periodo');
        $ocupacion = $this->service->ocupacion($anio, $plan->estacion_id, $plan->id);
        $catalogo = VacacionCatalogoPeriodo::query()->where('anio', $anio)->orderBy('inicio')->orderBy('dias')->get();

        return view('vacaciones.seleccion.mi', [
            'plan' => $plan,
            'anio' => $anio,
            'catalogo' => $catalogo->groupBy('trimestre'),
            'tope' => $this->service->tope($anio, $plan->estacion_id),
            'picos' => $catalogo->mapWithKeys(fn ($p) => [$p->id => $this->service->pico($p, $ocupacion)])->all(),
            'elegidos' => $plan->selecciones->pluck('catalogo_periodo_id', 'trimestre')->all(),
        ]);
    }

    public function guardar(GuardarSeleccionVacacionRequest $request): RedirectResponse
    {
        $this->service->guardar($this->miPlan($request), $request->validated('periodos'));

        return back()->with('status', __('Se guardó tu selección. Falta enviarla para que la revise la Zona.'));
    }

    public function enviar(Request $request): RedirectResponse
    {
        $this->authorize('propio', VacacionPlan::class);
        $this->service->enviar($this->miPlan($request));

        return back()->with('status', __('Enviaste tu selección. La Zona la revisará.'));
    }

    public function retirar(Request $request): RedirectResponse
    {
        $this->authorize('propio', VacacionPlan::class);
        $this->service->retirar($this->miPlan($request));

        return back()->with('status', __('Retiraste tu envío; ya puedes cambiar tu selección.'));
    }

    private function miPlan(Request $request): VacacionPlan
    {
        return $this->service->planDe($request->user()->empleado, (int) config('vacaciones.anio_seleccion'));
    }
}
