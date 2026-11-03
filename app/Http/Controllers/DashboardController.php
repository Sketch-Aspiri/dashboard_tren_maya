<?php

namespace App\Http\Controllers;

use App\Services\Asistente\ModeloLenguaje;
use App\Services\DashboardService;
use App\Services\InicioEstacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Dashboard del Jefe de Zona: indicadores, secciones actualizadas
     * recientemente y el asistente de preguntas (si la IA está configurada).
     */
    public function index(Request $request, ModeloLenguaje $modelo, InicioEstacionService $inicio): View
    {
        // Every login flow lands on the dashboard route. A station account
        // has no zone dashboard (its Gate stays closed): it gets its own home
        // with its approved sections and today's attendance status.
        if (Gate::allows('view-inicio-estacion')) {
            return view('dashboard.estacion', ['inicio' => $inicio->para($request->user())]);
        }

        Gate::authorize('view-dashboard');

        return view('dashboard', [
            'kpis' => $this->dashboard->kpis(Gate::allows('view-asistencia-zona')),
            'recientes' => $this->dashboard->seccionesRecientes(),
            'asistenteDisponible' => $modelo->disponible() && Gate::allows('usar-asistente'),
        ]);
    }
}
