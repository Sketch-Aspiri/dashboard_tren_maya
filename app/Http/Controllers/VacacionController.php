<?php

namespace App\Http\Controllers;

use App\Http\Requests\CambiarVacacionesRequest;
use App\Http\Requests\ConsultarVacacionRequest;
use App\Models\Estacion;
use App\Models\VacacionPeriodo;
use App\Models\VacacionPersona;
use App\Models\VacacionSolicitud;
use App\Services\Vacaciones\VacacionEdicionService;
use App\Support\AlcanceEstacion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Rol de vacaciones del año: cada estación ve solo el suyo; la Zona ve todos y puede filtrar.
 * Con ?editar=1 las fechas se vuelven editables: la estación propone, la Zona guarda directo.
 */
class VacacionController extends Controller
{
    public function __construct(private readonly VacacionEdicionService $service) {}

    public function index(ConsultarVacacionRequest $request): View
    {
        $usuario = $request->user();
        $esZona = AlcanceEstacion::esTotal($usuario);
        $estacionDeUsuario = AlcanceEstacion::estacionId($usuario);

        $periodos = VacacionPeriodo::query()->where('estado', VacacionPeriodo::VIGENTE)->orderByDesc('anio')->get();
        $periodo = $request->filled('anio') ? $periodos->firstWhere('anio', $request->integer('anio')) : $periodos->first();

        $personas = collect();
        $pendientes = [];

        if ($periodo !== null && ($esZona || $estacionDeUsuario !== null)) {
            $consulta = VacacionPersona::query()->with(['estacion:id,nombre,orden', 'tramos'])->where('periodo_id', $periodo->id);

            if (! $esZona) {
                $consulta->where('estacion_id', $estacionDeUsuario);
            } elseif ($request->filled('estacion')) {
                $consulta->where('estacion_id', $request->integer('estacion'));
            }

            if ($request->filled('q')) {
                $busqueda = addcslashes((string) $request->validated('q'), '%_\\');
                $consulta->where(fn ($q) => $q->where('nombre', 'like', '%'.$busqueda.'%')->orWhere('no_empleado', 'like', '%'.$busqueda.'%'));
            }

            $personas = $consulta->orderBy('orden')->get()->sortBy(fn (VacacionPersona $p) => sprintf('%03d|%s|%06d', $p->estacion?->orden ?? 999, $p->estacion?->nombre, $p->orden))->values();
            $pendientes = VacacionSolicitud::query()->where('periodo_id', $periodo->id)->where('estado', VacacionSolicitud::PENDIENTE)
                ->whereIn('tramo_id', $personas->flatMap->tramos->pluck('id'))->pluck('tramo_id')->flip()->all();
        }

        $puedeEditar = $periodo !== null && ($esZona || $estacionDeUsuario !== null);

        return view('vacaciones.index', [
            'periodos' => $periodos,
            'periodo' => $periodo,
            'grupos' => $personas->groupBy(fn (VacacionPersona $p) => $p->estacion?->nombre ?? __('Sin estación reconocida')),
            'pendientes' => $pendientes,
            'estaciones' => $esZona ? Estacion::query()->orderBy('orden')->get(['id', 'nombre']) : collect(),
            'esZona' => $esZona,
            'editar' => $puedeEditar && $request->boolean('editar'),
            'puedeEditar' => $puedeEditar,
            'pendientesZona' => $esZona ? VacacionSolicitud::query()->where('estado', VacacionSolicitud::PENDIENTE)->distinct('lote')->count('lote') : 0,
        ]);
    }

    public function cambiar(CambiarVacacionesRequest $request, VacacionPeriodo $periodo): RedirectResponse
    {
        $resultado = $this->service->cambiar($request->user(), $periodo, $request->validated('tramos'), $request->validated('motivo'));

        $mensaje = $resultado['modo'] === 'directo'
            ? __('Se guardaron :n cambio(s) en el rol de vacaciones.', ['n' => $resultado['cantidad']])
            : __('Se enviaron :n cambio(s) a la Zona para su autorización.', ['n' => $resultado['cantidad']]);

        return redirect()->route('vacaciones.index', ['anio' => $periodo->anio])->with('status', $mensaje);
    }
}
