<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubirOficiosComisionRequest;
use App\Models\OficioComision;
use App\Models\RegistroDiario;
use App\Services\OficiosComisionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Alta, baja y descarga de los oficios de comisión que se anexan al control de
 * asistencia. Controlador delgado: la política autoriza, el servicio trabaja.
 */
class OficioComisionController extends Controller
{
    public function __construct(private readonly OficiosComisionService $service) {}

    public function store(SubirOficiosComisionRequest $request): RedirectResponse
    {
        $estacion = $request->estacionObjetivo();

        try {
            $creados = $this->service->almacenar(
                $estacion,
                $request->file('archivos'),
                $request->validated('vigente_hasta'),
                $request->user(),
            );
        } catch (DomainException $e) {
            return back()->withErrors(['oficios_comision' => $e->getMessage()]);
        }

        activity('oficio_comision')->causedBy($request->user())->performedOn($estacion)
            ->withProperties(['archivos' => $creados->pluck('nombre_original')->all()])->log('subidos');

        return $this->volver($request, $estacion->id, $request->validated('fecha'))
            ->with('status', trans_choice('{1} Oficio de comisión agregado.|[2,*] :count oficios de comisión agregados.', $creados->count()));
    }

    public function destroy(Request $request, OficioComision $oficioComision): RedirectResponse
    {
        $this->authorize('delete', $oficioComision);

        $this->service->eliminar($oficioComision);

        activity('oficio_comision')->causedBy($request->user())->performedOn($oficioComision->estacion)
            ->withProperties(['archivo' => $oficioComision->nombre_original])->log('eliminado');

        return $this->volver($request, $oficioComision->estacion_id, $request->input('fecha'))
            ->with('status', __('Oficio de comisión eliminado.'));
    }

    public function download(Request $request, OficioComision $oficioComision): StreamedResponse
    {
        $this->authorize('view', $oficioComision);

        abort_unless($this->service->disco()->exists($oficioComision->ruta), 404);

        return $this->service->disco()->download($oficioComision->ruta, $oficioComision->nombre_original);
    }

    private function volver(Request $request, int $estacionId, mixed $fecha): RedirectResponse
    {
        $params = $request->user()->can('captureAnyEstacion', RegistroDiario::class)
            ? array_filter(['estacion' => $estacionId, 'fecha' => is_string($fecha) ? $fecha : null])
            : [];

        return redirect()->route('asistencia.captura.index', $params)->with('paso', 'oficio');
    }
}
