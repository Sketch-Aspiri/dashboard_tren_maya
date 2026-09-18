<?php

namespace App\Http\Controllers;

use App\Models\Estacion;
use App\Services\AsistenciaCapturaService;
use App\Services\AsistenciaDocumentoService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Zona-wide "¿quién ya capturó hoy?" oversight board (Etapa 3 — Control de
 * Asistencia Diaria, see the approved plan). Read-only for Jefe de Zona
 * and Administrador, per the `view-asistencia-zona` Gate — "Estación"
 * accounts never reach this screen, only their own station's capture
 * screen (AsistenciaCapturaController). Thin-controller shape per
 * .claude/rules/code-style.md.
 */
class AsistenciaZonaController extends Controller
{
    public function __construct(
        private readonly AsistenciaCapturaService $service,
        private readonly AsistenciaDocumentoService $documentos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('view-asistencia-zona');

        $fecha = $this->resolverFecha($request);

        return view('asistencia.zona.index', [
            'fecha' => $fecha,
            'resumen' => $this->service->resumenDelDia($fecha),
            'oficioZona' => $this->documentos->panelZona($fecha),
        ]);
    }

    /**
     * JSON data endpoint for the board, per
     * .claude/rules/api-conventions.md response envelope.
     */
    public function data(Request $request): JsonResponse
    {
        Gate::authorize('view-asistencia-zona');

        $fecha = $this->resolverFecha($request);

        $resumen = $this->service->resumenDelDia($fecha)->map(fn (array $fila) => [
            'estacion_id' => $fila['estacion']->id,
            'estacion_nombre' => $fila['estacion']->nombre,
            'total_roster' => $fila['total_roster'],
            'total_capturado' => $fila['total_capturado'],
            'capturado' => $fila['capturado'],
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $resumen,
            'error' => null,
            'meta' => ['fecha' => $fecha->toDateString()],
        ]);
    }

    public function show(Estacion $estacion, Request $request): View
    {
        Gate::authorize('view-asistencia-zona');

        $fecha = $this->resolverFecha($request);

        $detalle = $this->service->detalleDeEstacion($estacion, $fecha);

        return view('asistencia.zona.show', [
            'estacion' => $estacion,
            'fecha' => $fecha,
            'roster' => $detalle['roster'],
            'comisionadosVisitantes' => $detalle['comisionados_visitantes'],
            'comisionadosFuera' => $detalle['comisionados_fuera'],
        ]);
    }

    /**
     * Safe-parse-with-fallback for ?fecha=, same pattern as
     * AsistenciaCapturaController::index() — a malformed value falls back
     * to today rather than 500ing on what's a read-only screen load.
     */
    private function resolverFecha(Request $request): CarbonImmutable
    {
        if (! $request->filled('fecha')) {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::parse($request->query('fecha'));
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }
}
