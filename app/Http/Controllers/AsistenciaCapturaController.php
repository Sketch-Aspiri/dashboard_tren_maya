<?php

namespace App\Http\Controllers;

use App\Enums\EstatusAsistencia;
use App\Http\Requests\GuardarAsistenciaCapturaRequest;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Daily attendance capture screen (Fase 1 — Control de Asistencia Diaria).
 * Thin-controller shape per .claude/rules/code-style.md.
 */
class AsistenciaCapturaController extends Controller
{
    public function __construct(private readonly AsistenciaCapturaService $service) {}

    public function index(Request $request): View
    {
        $estacionIdInput = $request->filled('estacion') ? (int) $request->query('estacion') : null;

        $estacion = $this->service->resolveEstacionObjetivo($request->user(), $estacionIdInput);

        abort_if($estacion === null, 404);

        $this->authorize('captureFor', [RegistroDiario::class, $estacion]);

        // Estación-role accounts can only ever capture/see today (enforced
        // again, defense in depth, on save by
        // RegistroDiarioPolicy::captureForFecha()) — any ?fecha= they pass
        // is ignored, same treatment as ?estacion=. Administrador may load
        // a past date to correct it; without this, the correction screen
        // would show today's data while claiming to edit a past date, and
        // saving would silently overwrite that past day with today's
        // values (see code review finding).
        $fecha = CarbonImmutable::today();

        if ($request->user()->hasRole('Administrador') && $request->filled('fecha')) {
            try {
                $fecha = CarbonImmutable::parse($request->query('fecha'));
            } catch (\Throwable) {
                // Malformed ?fecha= falls back to today rather than 500ing
                // on what's just a read-only screen load.
            }
        }

        return view('asistencia.captura.index', [
            'estacion' => $estacion,
            'fecha' => $fecha,
            'roster' => $this->service->rosterFor($estacion, $fecha),
            'estatuses' => EstatusAsistencia::cases(),
            'estaciones' => $request->user()->hasRole('Administrador')
                ? Estacion::orderBy('orden')->orderBy('nombre')->get()
                : collect(),
            // Etapa 2 — comisionados_visitantes / comisionados_fuera (ver
            // el plan aprobado).
            'comisionadosVisitantes' => $this->service->comisionadosVisitantesFor($estacion, $fecha),
            'comisionadosFuera' => $this->service->comisionadosFueraFor($estacion, $fecha),
            'puedeGestionarComisionados' => $request->user()->can('createFor', [ComisionadoVisitante::class, $estacion])
                && $request->user()->can('createFor', [ComisionadoFuera::class, $estacion]),
        ]);
    }

    public function update(GuardarAsistenciaCapturaRequest $request): RedirectResponse
    {
        $estacion = $request->estacionObjetivo();

        $fecha = CarbonImmutable::parse($request->validated('fecha'));

        $this->service->guardar($estacion, $fecha, $request->validated('registros'), $request->user());

        return redirect()
            ->route('asistencia.captura.index', $request->user()->hasRole('Administrador')
                ? ['estacion' => $estacion->id, 'fecha' => $fecha->toDateString()]
                : [])
            ->with('status', __('Asistencia guardada correctamente.'));
    }
}
