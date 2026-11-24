<?php

namespace App\Http\Controllers;

use App\Http\Requests\AjustarDiasVacacionRequest;
use App\Http\Requests\ResolverVacacionPlanRequest;
use App\Models\VacacionPlan;
use App\Services\Vacaciones\SeleccionVacacionService;
use App\Support\AlcanceEstacion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Bandeja de la selección de vacaciones: la Zona autoriza o rechaza; la estación solo mira a su gente.
 */
class VacacionRevisionController extends Controller
{
    private const ESTADOS = [VacacionPlan::ENVIADA, VacacionPlan::AUTORIZADA, VacacionPlan::RECHAZADA, VacacionPlan::BORRADOR];

    public function __construct(private readonly SeleccionVacacionService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', VacacionPlan::class);

        $anio = (int) config('vacaciones.anio_seleccion');
        $esZona = AlcanceEstacion::esTotal($request->user());
        $estacionId = AlcanceEstacion::estacionId($request->user());
        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? (string) $request->query('estado') : VacacionPlan::ENVIADA;

        $base = VacacionPlan::query()->where('anio', $anio);

        if (! $esZona) {
            $estacionId === null ? $base->whereRaw('1 = 0') : $base->where('estacion_id', $estacionId);
        }

        $conteos = (clone $base)->selectRaw('estado, count(*) as total')->groupBy('estado')->pluck('total', 'estado')->all();
        $planes = (clone $base)->with(['empleado:id,no_empleado,nombre_completo', 'estacion:id,nombre,orden', 'selecciones.periodo'])
            ->where('estado', $estado)->orderBy('estacion_id')->orderBy('id')->get();

        return view('vacaciones.seleccion.revision', [
            'planes' => $planes->groupBy(fn (VacacionPlan $p) => $p->estacion?->nombre ?? '—'),
            'anio' => $anio, 'estado' => $estado, 'estados' => self::ESTADOS, 'conteos' => $conteos, 'esZona' => $esZona,
        ]);
    }

    public function autorizar(ResolverVacacionPlanRequest $request, VacacionPlan $plan): RedirectResponse
    {
        $this->service->autorizar($plan, $request->user(), $request->validated('comentario'));

        return back()->with('status', __('Selección autorizada.'));
    }

    public function rechazar(ResolverVacacionPlanRequest $request, VacacionPlan $plan): RedirectResponse
    {
        $this->service->rechazar($plan, $request->user(), $request->validated('comentario'));

        return back()->with('status', __('Selección rechazada; la persona podrá corregirla.'));
    }

    public function dias(AjustarDiasVacacionRequest $request, VacacionPlan $plan): RedirectResponse
    {
        if (! $plan->editable()) {
            return back()->withErrors(['plan' => __('Solo se ajustan los días de selecciones que aún no se envían.')]);
        }

        $plan->update(['dias_correspondientes' => $request->validated('dias')]);

        return back()->with('status', __('Días ajustados.'));
    }
}
