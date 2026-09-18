<?php

namespace App\Http\Controllers;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Services\AsistenciaCapturaService;
use App\Services\EstadisticaDiariaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(
        private readonly AsistenciaCapturaService $asistenciaCapturaService,
        private readonly EstadisticaDiariaService $estadisticaDiariaService,
    ) {}

    /**
     * Show the Jefe de Zona dashboard.
     *
     * Only KPIs backed by real data are shown; the placeholder cards were
     * removed. Further indicators stay blocked on the definitions the Jefe
     * de Zona has not yet provided (see CLAUDE.md "Pendientes bloqueados
     * por información externa").
     */
    public function index(Request $request): View|RedirectResponse
    {
        // Every login flow lands on the dashboard route. A station account
        // has no dashboard (its Gate stays closed), so its home is its own
        // capture screen instead of a dead-end 403.
        if ($request->user()?->hasRole('Estación')) {
            return redirect()->route('asistencia.captura.index');
        }

        Gate::authorize('view-dashboard');

        $kpis = [];

        if (Gate::allows('view-asistencia-zona')) {
            array_unshift($kpis, $this->asistenciaZonaKpi());
        }

        $estadisticasChart = null;

        // Módulo "Estadísticas" — first real (non-dummy) chart on the
        // dashboard. resumenMensualPorEstacion() is intentionally never
        // scoped per-estación (see EstadisticaDiariaService) — safe here
        // only because 'view-dashboard' above already restricts this whole
        // page to Jefe de Zona/Administrador, never "Estación".
        if (Gate::allows('viewAny', EstadisticaDiaria::class)) {
            $resumenDelMes = $this->estadisticaDiariaService->resumenMensualPorEstacion(
                CarbonImmutable::today()->year,
                CarbonImmutable::today()->month,
            );

            array_push($kpis, ...$this->estadisticasDelMesKpis($resumenDelMes));
            $estadisticasChart = $this->estadisticasDelMesChartData($resumenDelMes);
        }

        return view('dashboard', ['kpis' => $kpis, 'estadisticasChart' => $estadisticasChart]);
    }

    /**
     * @return array{label: string, value: string, hint: string}
     */
    private function asistenciaZonaKpi(): array
    {
        $resumen = $this->asistenciaCapturaService->resumenDelDia(CarbonImmutable::today());

        $totalEstaciones = $resumen->count();
        $totalCapturadas = $resumen->where('capturado', true)->count();

        return [
            'label' => 'Estaciones capturadas hoy',
            'value' => "{$totalCapturadas}/{$totalEstaciones}",
            'hint' => 'Control de Asistencia Diaria — Zona Oriente.',
        ];
    }

    /**
     * @param  Collection<int, array{estacion: Estacion, abordan: int, boletos_vendidos: int}>  $resumenDelMes
     * @return list<array{label: string, value: string, hint: string}>
     */
    private function estadisticasDelMesKpis(Collection $resumenDelMes): array
    {
        return [
            [
                'label' => 'Pasajeros del mes',
                'value' => number_format($resumenDelMes->sum('abordan')),
                'hint' => 'Estadísticas — suma de todas las estaciones, mes actual.',
            ],
            [
                'label' => 'Boletos vendidos del mes',
                'value' => number_format($resumenDelMes->sum('boletos_vendidos')),
                'hint' => 'Estadísticas — suma de todas las estaciones, mes actual.',
            ],
        ];
    }

    /**
     * @param  Collection<int, array{estacion: Estacion, abordan: int, boletos_vendidos: int}>  $resumenDelMes
     * @return array{labels: list<string>, values: list<int>, valuesBoletos: list<int>}
     */
    private function estadisticasDelMesChartData(Collection $resumenDelMes): array
    {
        return [
            'labels' => $resumenDelMes->pluck('estacion.nombre')->all(),
            'values' => $resumenDelMes->pluck('abordan')->all(),
            'valuesBoletos' => $resumenDelMes->pluck('boletos_vendidos')->all(),
        ];
    }
}
