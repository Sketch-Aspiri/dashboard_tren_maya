<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateElevadorRequest;
use App\Http\Requests\UpdateEscaleraElectricaRequest;
use App\Http\Requests\UpdateEstatusViaAndenRequest;
use App\Models\Elevador;
use App\Models\EscaleraElectrica;
use App\Models\EstatusViaAnden;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Módulo "Controles" (escaleras eléctricas / elevadores / estatus de vías y
 * andenes), cargado desde el
 * ANEXO de la Jefatura de Zona — ver
 * app/Console/Commands/ImportControlesCommand.php. Mismo criterio de
 * alcance que "Estadísticas": "Jefe de Zona" y "Administrador" ven y
 * editan todas las estaciones; "Estación" solo la suya.
 */
class ControlesController extends Controller
{
    public function escalerasElectricas(Request $request): View
    {
        $this->authorize('viewAny', EscaleraElectrica::class);

        $query = EscaleraElectrica::query()->with('estacion')->orderBy('estacion_id')->orderBy('id');
        $this->scopeToEstacionDelUsuario($query, $request);
        $escaleras = $query->get();

        return view('controles.escaleras-electricas', [
            'porEstacion' => $escaleras->groupBy(fn (EscaleraElectrica $escalera) => $escalera->estacion->nombre),
            'puedeEditar' => $this->puedeEditarPorEstacion($escaleras, EscaleraElectrica::class, $request),
        ]);
    }

    public function editarEscaleraElectrica(EscaleraElectrica $escalera): View
    {
        $escalera->load('estacion');

        $this->authorize('manageFor', [EscaleraElectrica::class, $escalera->estacion]);

        return view('controles.escaleras-electricas-editar', ['escalera' => $escalera]);
    }

    public function actualizarEscaleraElectrica(EscaleraElectrica $escalera, UpdateEscaleraElectricaRequest $request): RedirectResponse
    {
        $escalera->update($request->validated());

        return redirect()
            ->route('controles.escaleras-electricas.index')
            ->with('status', __('Escalera eléctrica actualizada correctamente.'));
    }

    public function elevadores(Request $request): View
    {
        $this->authorize('viewAny', Elevador::class);

        $query = Elevador::query()->with('estacion')->orderBy('estacion_id')->orderBy('id');
        $this->scopeToEstacionDelUsuario($query, $request);
        $elevadores = $query->get();

        return view('controles.elevadores', [
            'porEstacion' => $elevadores->groupBy(fn (Elevador $elevador) => $elevador->estacion->nombre),
            'puedeEditar' => $this->puedeEditarPorEstacion($elevadores, Elevador::class, $request),
        ]);
    }

    public function editarElevador(Elevador $elevador): View
    {
        $elevador->load('estacion');

        $this->authorize('manageFor', [Elevador::class, $elevador->estacion]);

        return view('controles.elevadores-editar', ['elevador' => $elevador]);
    }

    public function actualizarElevador(Elevador $elevador, UpdateElevadorRequest $request): RedirectResponse
    {
        $elevador->update($request->validated());

        return redirect()
            ->route('controles.elevadores.index')
            ->with('status', __('Elevador actualizado correctamente.'));
    }

    public function estatusViasAndenes(Request $request): View
    {
        $this->authorize('viewAny', EstatusViaAnden::class);

        $query = EstatusViaAnden::query()->with('estacion')->orderBy('estacion_id')->orderBy('via');
        $this->scopeToEstacionDelUsuario($query, $request);
        $registros = $query->get();

        return view('controles.estatus-vias-andenes', [
            'porEstacion' => $registros->groupBy(fn (EstatusViaAnden $registro) => $registro->estacion->nombre),
            'puedeEditar' => $this->puedeEditarPorEstacion($registros, EstatusViaAnden::class, $request),
        ]);
    }

    public function editarEstatusViaAnden(EstatusViaAnden $estatusViaAnden): View
    {
        $estatusViaAnden->load('estacion');

        $this->authorize('manageFor', [EstatusViaAnden::class, $estatusViaAnden->estacion]);

        return view('controles.estatus-vias-andenes-editar', ['registro' => $estatusViaAnden]);
    }

    public function actualizarEstatusViaAnden(EstatusViaAnden $estatusViaAnden, UpdateEstatusViaAndenRequest $request): RedirectResponse
    {
        $estatusViaAnden->update($request->validated());

        return redirect()
            ->route('controles.estatus-vias-andenes.index')
            ->with('status', __('Estatus de vía y andén actualizado correctamente.'));
    }

    /**
     * "Estación" solo ve el inventario de su propia estación; Jefe de Zona
     * y Administrador ven todas. Mismo criterio de alcance que
     * EstadisticaDiariaPolicy::manageFor().
     *
     * @param  Builder<EscaleraElectrica|Elevador|EstatusViaAnden>  $query
     */
    private function scopeToEstacionDelUsuario($query, Request $request): void
    {
        $user = $request->user();

        if ($user->hasRole('Estación')) {
            $query->where('estacion_id', $user->estacion_id);
        }
    }

    /**
     * Whether the current user may edit each estación present in
     * $equipos, keyed by estación id — computed once here instead of
     * per-row in the view (never duplicating manageFor()'s rule there).
     *
     * @param  Collection<int, EscaleraElectrica|Elevador|EstatusViaAnden>  $equipos
     * @param  class-string<EscaleraElectrica|Elevador|EstatusViaAnden>  $modelClass
     * @return Collection<int, bool>
     */
    private function puedeEditarPorEstacion(Collection $equipos, string $modelClass, Request $request): Collection
    {
        return $equipos->pluck('estacion')->unique('id')->mapWithKeys(
            fn ($estacion) => [$estacion->id => $request->user()->can('manageFor', [$modelClass, $estacion])],
        );
    }
}
