<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarGastoEnergeticoRequest;
use App\Http\Requests\GuardarGastoEnergeticoRequest;
use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use App\Services\GastoEnergeticoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * "Estadísticas" -> Gasto energético: pagos mensuales de energía eléctrica y
 * agua por estación (resumen de la zona, captura por estación y baja de un
 * pago). Thin-controller shape per .claude/rules/code-style.md. Mismo
 * criterio de alcance que "Estadísticas": "Jefe de Zona" y "Administrador"
 * ven/editan todas las estaciones; "Estación" solo la suya
 * (ServicioEstacionPolicy::manageFor()). La lectura/escritura de la
 * consulta (`?anio=`) vive en las Form Requests; la baja en
 * PagoServicioPolicy.
 */
class GastoEnergeticoController extends Controller
{
    public function __construct(private readonly GastoEnergeticoService $service) {}

    public function index(ConsultarGastoEnergeticoRequest $request): View
    {
        $anio = $this->resolverAnio($request);
        $resumen = $this->service->resumenAnual($request->user(), $anio);

        return view('estadisticas.gasto-energetico.index', [
            'anio' => $anio,
            'resumen' => $resumen,
            // resumenAnual() already scopes "Estación"-role users to their
            // own estación, so every row here is always manageable by them
            // — computed explicitly anyway (never duplicating the Policy
            // rule in the view), mismo criterio que EstadisticaController::index().
            'puedeEditar' => collect($resumen)
                ->flatMap(fn (array $bloque) => $bloque['filas'])
                ->pluck('servicio.estacion')
                ->unique('id')
                ->mapWithKeys(fn (Estacion $estacion) => [$estacion->id => $request->user()->can('manageFor', [ServicioEstacion::class, $estacion])]),
        ]);
    }

    public function show(Estacion $estacion, ConsultarGastoEnergeticoRequest $request): View
    {
        $this->authorize('manageFor', [ServicioEstacion::class, $estacion]);

        $anio = $this->resolverAnio($request);

        return view('estadisticas.gasto-energetico.show', [
            'estacion' => $estacion,
            'anio' => $anio,
            'bloques' => $this->service->datosDeEstacion($estacion, $anio),
            // PagoServicioPolicy::delete() depends only on the role, so any
            // instance answers for every row of the page.
            'puedeEliminar' => $request->user()->can('delete', new PagoServicio),
        ]);
    }

    public function update(Estacion $estacion, GuardarGastoEnergeticoRequest $request): RedirectResponse
    {
        $anio = (int) $request->validated('anio');

        $this->service->guardar($estacion, $anio, $request->validated('servicios'));

        return redirect()
            ->route('estadisticas.gasto-energetico.show', ['estacion' => $estacion->id, 'anio' => $anio])
            ->with('status', __('Gasto energético guardado correctamente.'));
    }

    public function destroy(Estacion $estacion, PagoServicio $pago): RedirectResponse
    {
        $this->authorize('delete', $pago);

        abort_unless($pago->servicio->estacion_id === $estacion->id, 404);

        $anio = $pago->anio;

        $pago->delete();

        return redirect()
            ->route('estadisticas.gasto-energetico.show', ['estacion' => $estacion->id, 'anio' => $anio])
            ->with('status', __('Registro eliminado correctamente.'));
    }

    /**
     * `?anio=` already validated by the Form Request; defaults to the latest
     * year with payments (or the current one when nothing is loaded yet).
     */
    private function resolverAnio(ConsultarGastoEnergeticoRequest $request): int
    {
        return (int) ($request->validated('anio') ?? $this->service->aniosDisponibles()[0] ?? now()->year);
    }
}
