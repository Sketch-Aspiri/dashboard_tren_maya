<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    /**
     * Show the Jefe de Zona dashboard.
     *
     * The KPI cards below use placeholder/dummy data on purpose: the real
     * indicators are blocked on the data model the Jefe de Zona has not
     * yet provided (see CLAUDE.md "Pendientes bloqueados por información
     * externa"). Swap `dummyKpis()` for a real query once that lands.
     */
    public function index(Request $request): View
    {
        Gate::authorize('view-dashboard');

        return view('dashboard', [
            'kpis' => $this->dummyKpis(),
        ]);
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
