<?php

namespace App\Http\Controllers;

use App\Services\Asistente\ModeloLenguaje;
use App\Services\DashboardService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    /**
     * Dashboard del Jefe de Zona: indicadores, secciones actualizadas
     * recientemente y el asistente de preguntas (si la IA está configurada).
     */
    public function index(Request $request, ModeloLenguaje $modelo): View|RedirectResponse
    {
        // Every login flow lands on the dashboard route. A station account
        // has no dashboard (its Gate stays closed), so its home is its own
        // capture screen instead of a dead-end 403.
        if ($request->user()?->hasRole('Estación')) {
            return redirect()->route('asistencia.captura.index');
        }

        Gate::authorize('view-dashboard');

        return view('dashboard', [
            'kpis' => $this->dashboard->kpis(Gate::allows('view-asistencia-zona')),
            'recientes' => $this->dashboard->seccionesRecientes(),
            'asistenteDisponible' => $modelo->disponible() && Gate::allows('usar-asistente'),
        ]);
    }
}
