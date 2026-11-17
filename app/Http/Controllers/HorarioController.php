<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarHorarioRequest;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Support\AlcanceEstacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Horario por semana: cada estación ve solo el suyo; la Zona ve todos y puede
 * filtrar por estación. El alcance se aplica aquí a TODA consulta (falla cerrada).
 */
class HorarioController extends Controller
{
    public function index(ConsultarHorarioRequest $request): View
    {
        $usuario = $request->user();
        $esZona = AlcanceEstacion::esTotal($usuario);
        $estacionDeUsuario = AlcanceEstacion::estacionId($usuario);

        $periodos = HorarioPeriodo::query()->where('estado', HorarioPeriodo::VIGENTE)->orderByDesc('anio')->orderByDesc('mes')->get();
        $periodo = $this->periodoElegido($periodos, $request->integer('anio') ?: null, $request->integer('mes') ?: null);

        $inicio = $this->inicioDeSemana($request->validated('desde'), $periodo);
        $dias = $inicio === null ? [] : array_map(fn (int $i) => $inicio->addDays($i), range(0, 6));

        $grupos = [];
        $pendientes = [];

        if ($periodo !== null && $inicio !== null && ($esZona || $estacionDeUsuario !== null)) {
            $consulta = HorarioTurno::query()
                ->with('estacion:id,nombre,orden')
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
                ->pluck('id', 'turno_id')->all();
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
            'puedeSolicitar' => ! $esZona && $estacionDeUsuario !== null,
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

        return $periodos->first(fn (HorarioPeriodo $p) => $p->anio === $hoy->year && $p->mes === $hoy->month) ?? $periodos->first();
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
     * Estación → persona → días, para pintar la matriz semanal.
     *
     * @param  Collection<int, HorarioTurno>  $turnos
     * @return array<string, array<string, array{nombre: string, puesto: string|null, no_empleado: string, celdas: array<string, HorarioTurno>}>>
     */
    private function agrupar($turnos): array
    {
        $grupos = [];

        foreach ($turnos->sortBy(fn (HorarioTurno $t) => sprintf('%03d|%s', $t->estacion?->orden ?? 999, $t->estacion?->nombre ?? '')) as $turno) {
            $estacion = $turno->estacion?->nombre ?? __('Sin estación reconocida');
            $grupos[$estacion][$turno->no_empleado] ??= ['nombre' => $turno->nombre, 'puesto' => $turno->puesto, 'no_empleado' => $turno->no_empleado, 'celdas' => []];
            $grupos[$estacion][$turno->no_empleado]['celdas'][$turno->fecha->toDateString()] = $turno;
        }

        return $grupos;
    }
}
