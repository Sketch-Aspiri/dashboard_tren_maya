<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarHorarioRequest;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Services\Horarios\HorarioEdicionService;
use App\Support\AlcanceEstacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Horario por semana: cada estación ve solo el suyo; la Zona ve todos (también
 * los borradores) y puede filtrar por estación. El alcance se aplica aquí a TODA
 * consulta (falla cerrada). Con ?editar=1 las celdas se vuelven editables: la
 * estación propone (la Zona autoriza) y la Zona guarda directo.
 */
class HorarioController extends Controller
{
    public function index(ConsultarHorarioRequest $request): View
    {
        $usuario = $request->user();
        $esZona = AlcanceEstacion::esTotal($usuario);
        $estacionDeUsuario = AlcanceEstacion::estacionId($usuario);

        $periodos = HorarioPeriodo::query()
            ->whereIn('estado', $esZona ? [HorarioPeriodo::VIGENTE, HorarioPeriodo::BORRADOR] : [HorarioPeriodo::VIGENTE])
            ->orderByDesc('anio')->orderByDesc('mes')->get();
        $periodo = $this->periodoElegido($periodos, $request->integer('anio') ?: null, $request->integer('mes') ?: null);

        $inicio = $this->inicioDeSemana($request->validated('desde'), $periodo);
        $dias = $inicio === null ? [] : array_map(fn (int $i) => $inicio->addDays($i), range(0, 6));

        $puedeSolicitar = ! $esZona && $estacionDeUsuario !== null && $periodo?->estado === HorarioPeriodo::VIGENTE;
        $puedeEditar = $periodo !== null && ($esZona || $puedeSolicitar);
        $editar = $puedeEditar && $request->boolean('editar');

        $grupos = [];
        $pendientes = collect();

        if ($periodo !== null && $inicio !== null && ($esZona || $estacionDeUsuario !== null)) {
            $consulta = HorarioTurno::query()
                ->with(['estacion:id,nombre,orden', 'editor:id,name'])
                ->where('periodo_id', $periodo->id)
                ->whereBetween('fecha', [$inicio->toDateString(), $inicio->addDays(6)->toDateString()]);

            if (! $esZona) {
                $consulta->where('estacion_id', $estacionDeUsuario);
            } elseif ($request->filled('estacion')) {
                $consulta->where('estacion_id', $request->integer('estacion'));
            }

            if ($request->filled('q')) {
                $busqueda = addcslashes((string) $request->validated('q'), '%_\\');
                $consulta->where(fn ($q) => $q->where('nombre', 'like', '%'.$busqueda.'%')->orWhere('no_empleado', 'like', '%'.$busqueda.'%'));
            }

            $turnos = $consulta->orderBy('nombre')->orderBy('fecha')->get();
            $grupos = $this->agrupar($turnos);
            $pendientes = HorarioSolicitud::query()
                ->where('estado', HorarioSolicitud::PENDIENTE)
                ->whereIn('turno_id', $turnos->pluck('id'))
                ->get()->keyBy('turno_id');
        }

        return view('horarios.index', [
            'periodos' => $periodos,
            'periodo' => $periodo,
            'inicio' => $inicio,
            'dias' => $dias,
            'grupos' => $grupos,
            'pendientes' => $pendientes,
            'estaciones' => $esZona ? Estacion::query()->orderBy('orden')->get(['id', 'nombre']) : collect(),
            'esZona' => $esZona,
            'puedeSolicitar' => $puedeSolicitar,
            'puedeEditar' => $puedeEditar,
            'editar' => $editar,
            'empleadosDisponibles' => $esZona && $periodo !== null ? $this->empleadosDisponibles($periodo) : collect(),
            'huella' => fn (HorarioTurno $t) => HorarioEdicionService::huella($t),
            'pendientesZona' => $esZona ? HorarioSolicitud::query()->where('estado', HorarioSolicitud::PENDIENTE)->count() : 0,
        ]);
    }

    /**
     * @param  Collection<int, HorarioPeriodo>  $periodos
     */
    private function periodoElegido($periodos, ?int $anio, ?int $mes): ?HorarioPeriodo
    {
        if ($anio !== null && $mes !== null) {
            return $periodos->first(fn (HorarioPeriodo $p) => $p->anio === $anio && $p->mes === $mes);
        }

        $hoy = CarbonImmutable::now('America/Cancun');

        return $periodos->first(fn (HorarioPeriodo $p) => $p->anio === $hoy->year && $p->mes === $hoy->month && $p->estado === HorarioPeriodo::VIGENTE)
            ?? $periodos->first(fn (HorarioPeriodo $p) => $p->estado === HorarioPeriodo::VIGENTE)
            ?? $periodos->first();
    }

    private function inicioDeSemana(?string $desde, ?HorarioPeriodo $periodo): ?CarbonImmutable
    {
        if ($periodo === null) {
            return null;
        }

        if ($desde !== null) {
            return CarbonImmutable::createFromFormat('!Y-m-d', $desde)->startOfWeek(CarbonImmutable::MONDAY);
        }

        $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();
        $primero = CarbonImmutable::create($periodo->anio, $periodo->mes, 1);

        $base = $hoy->year === $periodo->anio && $hoy->month === $periodo->mes ? $hoy : $primero;

        return $base->startOfWeek(CarbonImmutable::MONDAY);
    }

    /**
     * Personal activo de las estaciones del horario que todavía no está en él (para "Agregar persona").
     *
     * @return Collection<int, Empleado>
     */
    private function empleadosDisponibles(HorarioPeriodo $periodo): Collection
    {
        $estaciones = HorarioTurno::query()->where('periodo_id', $periodo->id)->whereNotNull('estacion_id')->distinct()->pluck('estacion_id');
        $presentes = HorarioTurno::query()->where('periodo_id', $periodo->id)->distinct()->pluck('no_empleado');

        return Empleado::query()
            ->with('estacion:id,nombre')
            ->where('estatus', 'activo')
            ->whereIn('estacion_id', $estaciones)
            ->whereNotNull('no_empleado')->where('no_empleado', '!=', '')
            ->whereNotIn('no_empleado', $presentes)
            ->orderBy('estacion_id')->orderBy('nombre_completo')
            ->get(['id', 'no_empleado', 'nombre_completo', 'estacion_id']);
    }

    /**
     * Estación → persona → días, para pintar la matriz semanal.
     *
     * @param  Collection<int, HorarioTurno>  $turnos
     * @return array<string, array<string, array{nombre: string, puesto: string|null, no_empleado: string, estacion_id: int|null, celdas: array<string, HorarioTurno>}>>
     */
    private function agrupar($turnos): array
    {
        $grupos = [];

        foreach ($turnos->sortBy(fn (HorarioTurno $t) => sprintf('%03d|%s', $t->estacion?->orden ?? 999, $t->estacion?->nombre ?? '')) as $turno) {
            $estacion = $turno->estacion?->nombre ?? __('Sin estación reconocida');
            $grupos[$estacion][$turno->no_empleado] ??= ['nombre' => $turno->nombre, 'puesto' => $turno->puesto, 'no_empleado' => $turno->no_empleado, 'estacion_id' => $turno->estacion_id, 'celdas' => []];
            $grupos[$estacion][$turno->no_empleado]['celdas'][$turno->fecha->toDateString()] = $turno;
        }

        return $grupos;
    }
}
