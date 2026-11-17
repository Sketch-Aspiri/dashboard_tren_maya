<?php

namespace App\Http\Controllers;

use App\Http\Requests\AgregarPersonaHorarioRequest;
use App\Http\Requests\CrearHorarioRequest;
use App\Http\Requests\RellenarHorarioRequest;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Services\Horarios\HorarioCreacionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Armar el horario dentro del sistema (sin Excel). Solo la Zona
 * (HorarioPeriodoPolicy::gestionar); la lógica vive en HorarioCreacionService.
 */
class HorarioCreacionController extends Controller
{
    public function __construct(private readonly HorarioCreacionService $service) {}

    public function create(): View
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        $siguiente = CarbonImmutable::now('America/Cancun')->startOfMonth()->addMonth();

        return view('horarios.crear', [
            'estaciones' => Estacion::query()->orderBy('orden')->get(['id', 'nombre']),
            'borradores' => HorarioPeriodo::query()->where('estado', HorarioPeriodo::BORRADOR)->orderByDesc('anio')->orderByDesc('mes')->get(),
            'anio' => $siguiente->year,
            'mes' => $siguiente->month,
        ]);
    }

    public function store(CrearHorarioRequest $request): RedirectResponse
    {
        $periodo = $this->service->crear(
            $request->user(),
            (int) $request->validated('anio'),
            (int) $request->validated('mes'),
            array_map('intval', $request->validated('estaciones')),
            (string) $request->validated('modo'),
        );

        return redirect()->route('horarios.index', ['anio' => $periodo->anio, 'mes' => $periodo->mes, 'editar' => 1])
            ->with('status', __('Borrador creado. Edita los turnos y, cuando esté listo, publícalo para avisar a las estaciones.'));
    }

    public function publicar(Request $request, HorarioPeriodo $periodo): RedirectResponse
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        $this->service->publicar($periodo, $request->user());

        return redirect()->route('horarios.index', ['anio' => $periodo->anio, 'mes' => $periodo->mes])
            ->with('status', __('Horario publicado: las estaciones ya lo ven y se les avisó.'));
    }

    public function descartar(Request $request, HorarioPeriodo $periodo): RedirectResponse
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        $this->service->descartar($periodo, $request->user());

        return redirect()->route('horarios.crear.create')->with('status', __('Borrador descartado.'));
    }

    public function rellenar(RellenarHorarioRequest $request, HorarioPeriodo $periodo): RedirectResponse
    {
        $cambiados = $this->service->rellenar($periodo, $request->user(), $request->validated());

        return back()->with('status', trans_choice('Se actualizó :n turno.|Se actualizaron :n turnos.', $cambiados, ['n' => $cambiados]));
    }

    public function agregarPersona(AgregarPersonaHorarioRequest $request, HorarioPeriodo $periodo): RedirectResponse
    {
        $datos = $request->validated();

        if (! empty($datos['empleado_id'])) {
            $empleado = Empleado::query()->findOrFail($datos['empleado_id']);
            $datos['no_empleado'] = (string) $empleado->no_empleado;
            $datos['nombre'] = (string) $empleado->nombre_completo;
            $datos['puesto'] ??= $empleado->puesto;
        }

        $this->service->agregarPersona($periodo, $request->user(), $datos);

        return back()->with('status', __(':nombre se agregó al horario.', ['nombre' => $datos['nombre']]));
    }

    public function quitarPersona(Request $request, HorarioPeriodo $periodo, string $noEmpleado): RedirectResponse
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        $this->service->quitarPersona($periodo, $request->user(), $noEmpleado);

        return back()->with('status', __('La persona se quitó del horario.'));
    }
}
