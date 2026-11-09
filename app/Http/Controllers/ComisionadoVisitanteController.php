<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComisionadoVisitanteRequest;
use App\Http\Requests\UpdateComisionadoVisitanteRequest;
use App\Http\Resources\ComisionadoVisitanteCapturaResource;
use App\Models\ComisionadoVisitante;
use App\Models\RegistroDiario;
use App\Services\ComisionadoVisitanteService;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreComisionadoVisitanteRequest $request): RedirectResponse|JsonResponse
    {
        $estacion = $request->estacionObjetivo();

        $visitante = $this->service->create($estacion, $request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->json(new ComisionadoVisitanteCapturaResource($visitante->refresh()), 201);
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante agregado correctamente.'));
    }

    public function update(UpdateComisionadoVisitanteRequest $request, ComisionadoVisitante $comisionadoVisitante): RedirectResponse|JsonResponse
    {
        $this->service->update($comisionadoVisitante, $request->validated());

        if ($request->expectsJson()) {
            return $this->json(new ComisionadoVisitanteCapturaResource($comisionadoVisitante->refresh()));
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante actualizado correctamente.'));
    }

    public function destroy(Request $request, ComisionadoVisitante $comisionadoVisitante): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $comisionadoVisitante);

        $this->service->delete($comisionadoVisitante);

        if ($request->expectsJson()) {
            return $this->json(null);
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado visitante eliminado correctamente.'));
    }

    /**
     * The capture screen saves these without reloading, so a commissioned
     * person never costs the unsaved attendance on the same page.
     */
    private function json(?ComisionadoVisitanteCapturaResource $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $data, 'error' => null, 'meta' => null], $status);
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
        return redirect()->route('asistencia.captura.index', $request->user()->can('captureAnyEstacion', RegistroDiario::class)
            ? array_filter([
                'estacion' => $request->input('estacion'),
                'fecha' => $request->input('fecha'),
            ], fn ($value) => $value !== null)
            : []);
    }
}
