<?php

namespace App\Services\Horarios;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Armar el rol de horarios dentro del sistema, sin Excel: crear un mes en
 * borrador (en blanco o copiando el mes anterior), rellenar por patrón,
 * agregar o quitar personas y publicar. Todo lo hace la Zona.
 */
final class HorarioCreacionService
{
    public const MODO_BLANCO = 'blanco';

    public const MODO_COPIAR = 'copiar';

    /** @var array<string, int>|null no_empleado => empleado_id */
    private ?array $empleados = null;

    public function __construct(private readonly AvisosEcosistema $avisos) {}

    /**
     * @param  list<int>  $estacionIds
     */
    public function crear(User $user, int $anio, int $mes, array $estacionIds, string $modo): HorarioPeriodo
    {
        $existente = HorarioPeriodo::query()->where('anio', $anio)->where('mes', $mes)->whereIn('estado', [HorarioPeriodo::VIGENTE, HorarioPeriodo::BORRADOR])->first();

        if ($existente !== null) {
            throw ValidationException::withMessages(['mes' => $existente->estado === HorarioPeriodo::VIGENTE
                ? __('Ese mes ya tiene un horario publicado: edítalo desde la vista del horario.')
                : __('Ese mes ya tiene un borrador: termínalo y publícalo, o descártalo.')]);
        }

        $personas = $modo === self::MODO_COPIAR
            ? $this->personasDelMesAnterior($anio, $mes, $estacionIds)
            : $this->personasActivas($estacionIds);

        if ($personas->isEmpty()) {
            throw ValidationException::withMessages(['estaciones' => $modo === self::MODO_COPIAR
                ? __('No hay un horario publicado del mes anterior para esas estaciones: empieza en blanco.')
                : __('Esas estaciones no tienen personal activo en el directorio.')]);
        }

        $this->empleados = null;

        return DB::transaction(function () use ($user, $anio, $mes, $personas, $modo) {
            $periodo = HorarioPeriodo::query()->create([
                'anio' => $anio,
                'mes' => $mes,
                'version' => (int) HorarioPeriodo::query()->where('anio', $anio)->where('mes', $mes)->max('version') + 1,
                'estado' => HorarioPeriodo::BORRADOR,
                'origen' => HorarioPeriodo::ORIGEN_SISTEMA,
                'subido_por' => $user->id,
            ]);

            $filas = [];

            foreach ($personas as $persona) {
                foreach ($this->diasDelMes($anio, $mes) as $dia) {
                    $filas[] = $this->fila($periodo, $persona, $dia, $persona['copia'][$dia->toDateString()] ?? null);
                }
            }

            foreach (array_chunk($filas, 500) as $lote) {
                DB::table('horario_turnos')->insert($lote);
            }

            activity('horario')->performedOn($periodo)->causedBy($user)
                ->withProperties(['personas' => $personas->count(), 'turnos' => count($filas), 'modo' => $modo])
                ->log('horario creado en borrador');

            return $periodo;
        });
    }

    /**
     * @param  array{estacion_id: int, no_empleado: string, nombre: string, puesto?: string|null}  $datos
     */
    public function agregarPersona(HorarioPeriodo $periodo, User $user, array $datos): int
    {
        $this->exigirEditable($periodo);

        if (HorarioTurno::query()->where('periodo_id', $periodo->id)->where('no_empleado', $datos['no_empleado'])->exists()) {
            throw ValidationException::withMessages(['no_empleado' => __('Esa persona ya está en este horario.')]);
        }

        $this->empleados = null;
        $persona = ['estacion_id' => $datos['estacion_id'], 'no_empleado' => $datos['no_empleado'], 'nombre' => $datos['nombre'], 'puesto' => $datos['puesto'] ?? null, 'copia' => []];
        $filas = $this->diasDelMes($periodo->anio, $periodo->mes)->map(fn (CarbonImmutable $dia) => $this->fila($periodo, $persona, $dia, null))->all();
        DB::table('horario_turnos')->insert($filas);

        activity('horario')->performedOn($periodo)->causedBy($user)->withProperties(['no_empleado' => $datos['no_empleado'], 'nombre' => $datos['nombre']])->log('persona agregada al horario');

        return count($filas);
    }

    /**
     * Quita a la persona del horario; sus solicitudes pendientes se cancelan.
     */
    public function quitarPersona(HorarioPeriodo $periodo, User $user, string $noEmpleado): int
    {
        $this->exigirEditable($periodo);

        return DB::transaction(function () use ($periodo, $user, $noEmpleado) {
            HorarioSolicitud::query()->where('periodo_id', $periodo->id)->where('no_empleado', $noEmpleado)->where('estado', HorarioSolicitud::PENDIENTE)
                ->update(['estado' => HorarioSolicitud::CANCELADA, 'resuelta_at' => now(), 'comentario_resolucion' => __('La persona se quitó del horario.')]);

            $borrados = HorarioTurno::query()->where('periodo_id', $periodo->id)->where('no_empleado', $noEmpleado)->delete();

            activity('horario')->performedOn($periodo)->causedBy($user)->withProperties(['no_empleado' => $noEmpleado, 'turnos' => $borrados])->log('persona quitada del horario');

            return $borrados;
        });
    }

    /**
     * Pone la misma jornada a una persona en los días de la semana elegidos dentro de un rango.
     *
     * @param  array{no_empleado: string, desde: string, hasta: string, dias: list<int|string>, jornada: string, comida?: string|null}  $datos
     */
    public function rellenar(HorarioPeriodo $periodo, User $user, array $datos): int
    {
        $this->exigirEditable($periodo);
        $dias = array_map('intval', $datos['dias']);
        $comida = trim((string) ($datos['comida'] ?? ''));

        $cambiados = DB::transaction(function () use ($periodo, $user, $datos, $dias, $comida) {
            $turnos = HorarioTurno::query()->where('periodo_id', $periodo->id)->where('no_empleado', $datos['no_empleado'])
                ->whereBetween('fecha', [$datos['desde'], $datos['hasta']])->lockForUpdate()->get()
                ->filter(fn (HorarioTurno $t) => in_array($t->fecha->dayOfWeekIso, $dias, true));

            foreach ($turnos as $turno) {
                if ($periodo->estado === HorarioPeriodo::VIGENTE) {
                    $turno->recordarOficial();
                }

                $turno->jornada = trim($datos['jornada']);
                $turno->comida = $comida !== '' ? $comida : $turno->comida;
                $turno->editado_por = $user->id;
                $turno->editado_at = now();
                $turno->save();
            }

            activity('horario')->performedOn($periodo)->causedBy($user)
                ->withProperties(['no_empleado' => $datos['no_empleado'], 'turnos' => $turnos->count(), 'jornada' => $datos['jornada']])->log('horario rellenado por patrón');

            return $turnos;
        });

        if ($periodo->estado === HorarioPeriodo::VIGENTE && $cambiados->isNotEmpty() && $cambiados->first()->estacion_id !== null) {
            $this->avisos->horarioEditado(Estacion::query()->findOrFail($cambiados->first()->estacion_id), $cambiados->count());
        }

        return $cambiados->count();
    }

    /**
     * El borrador pasa a vigente y se avisa a cada estación con turnos.
     */
    public function publicar(HorarioPeriodo $periodo, User $user): HorarioPeriodo
    {
        DB::transaction(function () use ($periodo, $user) {
            $actual = HorarioPeriodo::query()->lockForUpdate()->findOrFail($periodo->id);

            if ($actual->estado !== HorarioPeriodo::BORRADOR) {
                throw ValidationException::withMessages(['periodo' => __('Este horario no es un borrador.')]);
            }

            if (HorarioPeriodo::query()->where('anio', $actual->anio)->where('mes', $actual->mes)->where('estado', HorarioPeriodo::VIGENTE)->exists()) {
                throw ValidationException::withMessages(['periodo' => __('Ese mes ya tiene un horario publicado (se subió un Excel mientras tanto). Descarta este borrador.')]);
            }

            $sinAsignar = HorarioTurno::query()->where('periodo_id', $actual->id)->where('jornada', '')->count();
            $actual->update(['estado' => HorarioPeriodo::VIGENTE, 'publicado_at' => now()]);

            activity('horario')->performedOn($actual)->causedBy($user)->withProperties(['sin_asignar' => $sinAsignar])->log('horario publicado');
        });

        $periodo->refresh();

        Estacion::query()->whereIn('id', HorarioTurno::query()->where('periodo_id', $periodo->id)->whereNotNull('estacion_id')->distinct()->select('estacion_id'))->get()
            ->each(fn (Estacion $e) => $this->avisos->horarioPublicado($e, $periodo));

        return $periodo;
    }

    public function descartar(HorarioPeriodo $periodo, User $user): void
    {
        if ($periodo->estado !== HorarioPeriodo::BORRADOR) {
            throw ValidationException::withMessages(['periodo' => __('Solo se puede descartar un borrador.')]);
        }

        activity('horario')->performedOn($periodo)->causedBy($user)->log('borrador descartado');
        $periodo->delete();
    }

    private function exigirEditable(HorarioPeriodo $periodo): void
    {
        if (! in_array($periodo->estado, [HorarioPeriodo::VIGENTE, HorarioPeriodo::BORRADOR], true)) {
            throw ValidationException::withMessages(['periodo' => __('Esa versión del horario ya fue reemplazada.')]);
        }
    }

    /**
     * @param  list<int>  $estacionIds
     * @return Collection<int, array{estacion_id: int, no_empleado: string, nombre: string, puesto: string|null, copia: array<string, array{0: string, 1: string|null}>}>
     */
    private function personasActivas(array $estacionIds): Collection
    {
        return Empleado::query()
            ->where('estatus', 'activo')
            ->whereIn('estacion_id', $estacionIds)
            ->whereNotNull('no_empleado')->where('no_empleado', '!=', '')
            ->orderBy('estacion_id')->orderBy('orden_origen')->orderBy('nombre_completo')
            ->get(['estacion_id', 'no_empleado', 'nombre_completo', 'puesto'])
            ->unique('no_empleado')
            ->map(fn (Empleado $e) => [
                'estacion_id' => (int) $e->estacion_id,
                'no_empleado' => (string) $e->no_empleado,
                'nombre' => (string) $e->nombre_completo,
                'puesto' => $e->puesto,
                'copia' => [],
            ])->values();
    }

    /**
     * Cada fecha del mes nuevo toma lo que la persona tenía 28, 35 o 42 días antes (mismo día de la
     * semana), para conservar el patrón semanal sin importar cuántos días tenga cada mes.
     *
     * @param  list<int>  $estacionIds
     * @return Collection<int, array{estacion_id: int, no_empleado: string, nombre: string, puesto: string|null, copia: array<string, array{0: string, 1: string|null}>}>
     */
    private function personasDelMesAnterior(int $anio, int $mes, array $estacionIds): Collection
    {
        $anterior = CarbonImmutable::create($anio, $mes, 1)->subMonth();
        $periodo = HorarioPeriodo::query()->where('anio', $anterior->year)->where('mes', $anterior->month)->where('estado', HorarioPeriodo::VIGENTE)->first();

        if ($periodo === null) {
            return collect();
        }

        $turnos = HorarioTurno::query()->where('periodo_id', $periodo->id)->whereIn('estacion_id', $estacionIds)->orderBy('estacion_id')->orderBy('nombre')->orderBy('fecha')->get();

        return $turnos->groupBy('no_empleado')->map(function (Collection $propios) use ($anio, $mes) {
            $primero = $propios->first();
            $porFecha = $propios->keyBy(fn (HorarioTurno $t) => $t->fecha->toDateString());
            $copia = [];

            foreach ($this->diasDelMes($anio, $mes) as $dia) {
                foreach ([28, 35, 42] as $atras) {
                    $origen = $porFecha->get($dia->subDays($atras)->toDateString());

                    if ($origen !== null) {
                        $copia[$dia->toDateString()] = [$origen->jornada, $origen->comida];

                        break;
                    }
                }
            }

            return [
                'estacion_id' => (int) $primero->estacion_id,
                'no_empleado' => (string) $primero->no_empleado,
                'nombre' => $primero->nombre,
                'puesto' => $primero->puesto,
                'copia' => $copia,
            ];
        })->values();
    }

    /**
     * @return Collection<int, CarbonImmutable>
     */
    private function diasDelMes(int $anio, int $mes): Collection
    {
        $primero = CarbonImmutable::create($anio, $mes, 1);

        return collect(range(0, $primero->daysInMonth - 1))->map(fn (int $i) => $primero->addDays($i));
    }

    /**
     * @param  array{estacion_id: int, no_empleado: string, nombre: string, puesto: string|null}  $persona
     * @param  array{0: string, 1: string|null}|null  $copia
     * @return array<string, mixed>
     */
    private function fila(HorarioPeriodo $periodo, array $persona, CarbonImmutable $dia, ?array $copia): array
    {
        $ahora = Carbon::now();

        return [
            'periodo_id' => $periodo->id,
            'estacion_id' => $persona['estacion_id'],
            'no_empleado' => $persona['no_empleado'],
            'empleado_id' => ($this->empleados ??= Empleado::query()->whereNotNull('no_empleado')->pluck('id', 'no_empleado')->all())[$persona['no_empleado']] ?? null,
            'nombre' => $persona['nombre'],
            'puesto' => $persona['puesto'],
            'fecha' => $dia->toDateString(),
            'jornada' => $copia[0] ?? '',
            'comida' => $copia[1] ?? null,
            'semana' => 'Semana '.(intdiv($dia->day - 1, 7) + 1),
            'origen' => HorarioTurno::OFICIAL,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ];
    }
}
