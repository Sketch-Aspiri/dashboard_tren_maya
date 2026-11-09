<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComisionadoFueraRequest;
use App\Http\Requests\UpdateComisionadoFueraRequest;
use App\Http\Resources\ComisionadoFueraCapturaResource;
use App\Models\ComisionadoFuera;
use App\Models\RegistroDiario;
use App\Services\ComisionadoFueraService;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreComisionadoFueraRequest $request): RedirectResponse|JsonResponse
    {
        $comision = $this->service->create($request->validated(), $request->user());

        if ($request->expectsJson()) {
            return $this->json(new ComisionadoFueraCapturaResource($comision->refresh()), 201);
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera agregado correctamente.'));
    }

    public function update(UpdateComisionadoFueraRequest $request, ComisionadoFuera $comisionadoFuera): RedirectResponse|JsonResponse
    {
        $this->service->update($comisionadoFuera, $request->validated());

        if ($request->expectsJson()) {
            return $this->json(new ComisionadoFueraCapturaResource($comisionadoFuera->refresh()));
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera actualizado correctamente.'));
    }

    public function destroy(Request $request, ComisionadoFuera $comisionadoFuera): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $comisionadoFuera);

        $this->service->delete($comisionadoFuera);

        if ($request->expectsJson()) {
            return $this->json(null);
        }

        return $this->volverACaptura($request)
            ->with('status', __('Comisionado fuera eliminado correctamente.'));
    }

    /**
     * The capture screen saves these without reloading, so a commissioned
     * person never costs the unsaved attendance on the same page.
     */
    private function json(?ComisionadoFueraCapturaResource $data, int $status = 200): JsonResponse
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
