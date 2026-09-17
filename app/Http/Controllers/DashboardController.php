<?php

namespace App\Http\Controllers;

use App\Services\AsistenciaCapturaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(private readonly AsistenciaCapturaService $asistenciaCapturaService) {}

    /**
     * Show the Jefe de Zona dashboard.
     *
     * Most KPI cards below use placeholder/dummy data on purpose: the real
     * indicators are blocked on the data model the Jefe de Zona has not
     * yet provided (see CLAUDE.md "Pendientes bloqueados por información
     * externa"). Swap `dummyKpis()` for a real query once that lands. The
     * "Estaciones capturadas hoy" card (Etapa 3 — Control de Asistencia
     * Diaria) is the first real KPI, added alongside the dummy ones
     * exactly per that convention.
     */
    public function index(Request $request): View
    {
        Gate::authorize('view-dashboard');

        $kpis = $this->dummyKpis();

        if (Gate::allows('view-asistencia-zona')) {
            array_unshift($kpis, $this->asistenciaZonaKpi());
        }

        return view('dashboard', ['kpis' => $kpis]);
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
     * @return list<array{label: string, value: string, hint: string}>
     */
    private function dummyKpis(): array
    {
        return [
            [
                'label' => 'Registros totales (dummy)',
                'value' => '—',
                'hint' => 'Pendiente del modelo de datos real.',
            ],
            [
                'label' => 'Actividad reciente (dummy)',
                'value' => '—',
                'hint' => 'Pendiente de la definición de KPIs.',
            ],
        ];
    }
}
