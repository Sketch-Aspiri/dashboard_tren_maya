<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarGastoEnergeticoRequest;
use App\Http\Requests\GuardarGastoEnergeticoRequest;
use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Services\GastoEnergeticoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * "Estadísticas" -> Gasto energético: pagos mensuales de energía eléctrica y
 * agua por estación (resumen de la zona, captura por estación y baja de un
 * pago). Thin-controller shape per .claude/rules/code-style.md; la
 * autorización de lectura/escritura vive en las Form Requests
 * (ServicioEstacionPolicy) y la de baja en PagoServicioPolicy.
 */
class GastoEnergeticoController extends Controller
{
    public function __construct(private readonly GastoEnergeticoService $service) {}

    public function index(ConsultarGastoEnergeticoRequest $request): View
    {
        $anio = $this->resolverAnio($request);

        return view('estadisticas.gasto-energetico.index', [
            'anio' => $anio,
            'resumen' => $this->service->resumenAnual($anio),
        ]);
    }

    public function show(Estacion $estacion, ConsultarGastoEnergeticoRequest $request): View
    {
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
