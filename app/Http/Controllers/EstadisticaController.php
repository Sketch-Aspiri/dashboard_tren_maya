<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarEstadisticaMensualRequest;
use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Services\EstadisticaDiariaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "Estadísticas" module (flujo de pasajeros / boletos vendidos por
 * estación), ver el plan aprobado. Thin-controller shape per
 * .claude/rules/code-style.md. Dummy/seeded data structure per CLAUDE.md —
 * the real report/KPI shape for this data is still pending the Jefe de
 * Zona.
 */
class EstadisticaController extends Controller
{
    /**
     * Same range as the `min`/`max` on the `<input type="number">` year
     * pickers in the views and on GuardarEstadisticaMensualRequest's
     * `anio` rule — keeps a wildly out-of-range `?anio=` from a GET
     * request reaching CarbonImmutable::create() in the service, which
     * would silently overflow/underflow to an unrelated date instead of
     * erroring.
     */
    private const ANIO_MINIMO = 2000;

    private const ANIO_MAXIMO = 2100;

    public function __construct(private readonly EstadisticaDiariaService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', EstadisticaDiaria::class);

        $anio = $this->resolverAnio($request);

        $resumen = $this->service->resumenAnual($request->user(), $anio);

        return view('estadisticas.index', [
            'anio' => $anio,
            'resumen' => $resumen,
            'totalMensualAbordan' => $this->totalMensualPorIndicador($resumen, 'abordan'),
            'totalMensualBoletos' => $this->totalMensualPorIndicador($resumen, 'boletos_vendidos'),
            // resumenAnual() already scopes "Estación"-role users to their
            // own station, so every row here is always manageable by them
            // — computed explicitly anyway (never duplicating the Policy
            // rule in the view) so Administrador/Jefe de Zona, who see
            // every station, still get a correct per-row flag.
            'puedeEditar' => $resumen->mapWithKeys(
                fn (array $fila) => [$fila['estacion']->id => $request->user()->can('manageFor', [EstadisticaDiaria::class, $fila['estacion']])],
            ),
        ]);
    }

    public function show(Estacion $estacion, Request $request): View
    {
        $this->authorize('manageFor', [EstadisticaDiaria::class, $estacion]);

        $anio = $this->resolverAnio($request);
        $mes = $this->resolverMes($request);

        $registros = $this->service->registrosDelMes($estacion, $anio, $mes);

        return view('estadisticas.show', [
            'estacion' => $estacion,
            'anio' => $anio,
            'mes' => $mes,
            'registros' => $registros,
            'puedeEliminar' => $registros->mapWithKeys(
                fn (EstadisticaDiaria $registro) => [$registro->id => $request->user()->can('delete', $registro)],
            ),
        ]);
    }

    public function update(Estacion $estacion, GuardarEstadisticaMensualRequest $request): RedirectResponse
    {
        $estacionObjetivo = $request->estacionObjetivo();

        $anio = (int) $request->validated('anio');
        $mes = (int) $request->validated('mes');

        $this->service->guardarMes(
            $estacionObjetivo,
            $anio,
            $mes,
            $request->validated('dias', []),
            $request->user(),
        );

        return redirect()
            ->route('estadisticas.show', ['estacion' => $estacionObjetivo->id, 'anio' => $anio, 'mes' => $mes])
            ->with('status', __('Estadísticas guardadas correctamente.'));
    }

    public function destroy(Estacion $estacion, EstadisticaDiaria $registro): RedirectResponse
    {
        $this->authorize('delete', $registro);

        abort_unless($registro->estacion_id === $estacion->id, 404);

        $anio = $registro->fecha->year;
        $mes = $registro->fecha->month;

        $registro->delete();

        return redirect()
            ->route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => $anio, 'mes' => $mes])
            ->with('status', __('Registro eliminado correctamente.'));
    }

    /**
     * Safe-parse-with-fallback for `?anio=`, same pattern as
     * AsistenciaZonaController::resolverFecha() — a missing or
     * out-of-range value falls back to the current year rather than
     * reaching CarbonImmutable::create() (in the service) with a value
     * that could overflow/underflow.
     */
    private function resolverAnio(Request $request): int
    {
        if (! $request->filled('anio')) {
            return CarbonImmutable::today()->year;
        }

        $anio = (int) $request->query('anio');

        if ($anio < self::ANIO_MINIMO || $anio > self::ANIO_MAXIMO) {
            return CarbonImmutable::today()->year;
        }

        return $anio;
    }

    /**
     * Safe-parse-with-fallback for `?mes=` — same reasoning as
     * resolverAnio() above.
     */
    private function resolverMes(Request $request): int
    {
        if (! $request->filled('mes')) {
            return CarbonImmutable::today()->month;
        }

        $mes = (int) $request->query('mes');

        if ($mes < 1 || $mes > 12) {
            return CarbonImmutable::today()->month;
        }

        return $mes;
    }

    /**
     * Sums the given indicator ('abordan' or 'boletos_vendidos') across
     * every estación visible in $resumen, per month (1..12) — feeds the
     * annual line chart on estadisticas.index.
     *
     * @param  Collection<int, array{estacion: Estacion, abordan: array<int, int>, boletos_vendidos: array<int, int>}>  $resumen
     * @return array<int, int>
     */
    private function totalMensualPorIndicador(Collection $resumen, string $indicador): array
    {
        $totales = array_fill(1, 12, 0);

        foreach ($resumen as $fila) {
            foreach ($fila[$indicador] as $mes => $valor) {
                $totales[$mes] += $valor;
            }
        }

        return $totales;
    }
}
