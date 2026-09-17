<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComisionadoVisitanteRequest;
use App\Http\Requests\UpdateComisionadoVisitanteRequest;
use App\Models\ComisionadoVisitante;
use App\Services\ComisionadoVisitanteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Personal comisionado de otras coordinaciones presentes hoy" (Etapa 2 —
 * Control de Asistencia Diaria). Thin-controller shape per
 * .claude/rules/code-style.md — store/update/destroy only, no index/show:
 * entries are listed inline on the daily capture screen
 * (AsistenciaCapturaController::index()).
 */
class ComisionadoVisitanteController extends Controller
{
    public function __construct(private readonly ComisionadoVisitanteService $service) {}

    public function store(StoreComisionadoVisitanteRequest $request): RedirectResponse
    {
        $estacion = $request->estacionObjetivo();

        $this->service->create($estacion, $request->validated(), $request->user());

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante agregado correctamente.'));
    }

    public function update(UpdateComisionadoVisitanteRequest $request, ComisionadoVisitante $comisionadoVisitante): RedirectResponse
    {
        $this->service->update($comisionadoVisitante, $request->validated());

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante actualizado correctamente.'));
    }

    public function destroy(Request $request, ComisionadoVisitante $comisionadoVisitante): RedirectResponse
    {
        $this->authorize('delete', $comisionadoVisitante);

        $this->service->delete($comisionadoVisitante);

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante eliminado correctamente.'));
    }

    /**
     * Redirects back to the capture screen, preserving the current
     * ?estacion=/?fecha= query params for Administrador only — same
     * pattern AsistenciaCapturaController::update() already uses. An
     * "Estación" account always lands back on its own default (today,
     * own station) screen with no params needed.
     */
    private function volverACaptura(Request $request): RedirectResponse
    {
        return redirect()->route('asistencia.captura.index', $request->user()->hasRole('Administrador')
            ? array_filter([
                'estacion' => $request->input('estacion'),
                'fecha' => $request->input('fecha'),
            ], fn ($value) => $value !== null)
            : []);
    }
}
