<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarHorarioRequest;
use App\Http\Requests\ProponerCambiosHorarioRequest;
use App\Services\Horarios\HorarioEdicionService;
use Illuminate\Http\RedirectResponse;

/**
 * Edición desde la vista semanal: la estación propone (la Zona autoriza) y la
 * Zona guarda directo. Controlador delgado: la lógica vive en HorarioEdicionService.
 */
class HorarioEdicionController extends Controller
{
    public function __construct(private readonly HorarioEdicionService $service) {}

    public function proponer(ProponerCambiosHorarioRequest $request): RedirectResponse
    {
        $resultado = $this->service->proponer($request->user(), $request->validated('turnos'), (string) $request->validated('motivo'));

        return redirect()->route('horarios.index', array_filter(['anio' => $request->input('anio'), 'mes' => $request->input('mes'), 'desde' => $request->input('desde')]))
            ->with('status', trans_choice('Se envió :n cambio a Edificio. Te avisaremos cuando lo resuelvan; el horario no cambia hasta entonces.|Se enviaron :n cambios a Edificio. Te avisaremos cuando los resuelvan; el horario no cambia hasta entonces.', $resultado['creadas'], ['n' => $resultado['creadas']]));
    }

    public function guardar(GuardarHorarioRequest $request): RedirectResponse
    {
        $resultado = $this->service->guardarDirecto($request->user(), $request->validated('turnos'), $request->validated('nota'));

        $volver = redirect()->route('horarios.index', array_filter(['anio' => $request->input('anio'), 'mes' => $request->input('mes'), 'desde' => $request->input('desde'), 'estacion' => $request->input('estacion'), 'editar' => $request->input('seguir') ? 1 : null]))
            ->with('status', trans_choice('Se guardó :n cambio.|Se guardaron :n cambios.', $resultado['guardados'], ['n' => $resultado['guardados']]));

        if ($resultado['conflictos'] !== []) {
            $volver->withErrors(['conflictos' => __('No se guardaron porque otra persona los cambió mientras editabas: :lista. Revisa y vuelve a intentar.', ['lista' => implode(', ', $resultado['conflictos'])])]);
        }

        return $volver;
    }
}
