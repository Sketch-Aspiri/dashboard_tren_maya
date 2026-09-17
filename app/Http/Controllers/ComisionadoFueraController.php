<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComisionadoFueraRequest;
use App\Http\Requests\UpdateComisionadoFueraRequest;
use App\Models\ComisionadoFuera;
use App\Services\ComisionadoFueraService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Personal de Zona Oriente comisionado fuera hoy" (Etapa 2 — Control de
 * Asistencia Diaria). Thin-controller shape per
 * .claude/rules/code-style.md — store/update/destroy only, no index/show:
 * entries are listed inline on the daily capture screen
 * (AsistenciaCapturaController::index()).
 */
class ComisionadoFueraController extends Controller
{
    public function __construct(private readonly ComisionadoFueraService $service) {}

    public function store(StoreComisionadoFueraRequest $request): RedirectResponse
    {
        $this->service->create($request->validated(), $request->user());

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera agregado correctamente.'));
    }

    public function update(UpdateComisionadoFueraRequest $request, ComisionadoFuera $comisionadoFuera): RedirectResponse
    {
        $this->service->update($comisionadoFuera, $request->validated());

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera actualizado correctamente.'));
    }

    public function destroy(Request $request, ComisionadoFuera $comisionadoFuera): RedirectResponse
    {
        $this->authorize('delete', $comisionadoFuera);

        $this->service->delete($comisionadoFuera);

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera eliminado correctamente.'));
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
