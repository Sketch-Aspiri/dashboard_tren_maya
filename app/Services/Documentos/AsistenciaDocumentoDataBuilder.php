<?php

namespace App\Services\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Turns the day's captured attendance into the plain data structures the
 * oficio generators print. Station data reuses AsistenciaCapturaService's
 * roster query; zone data is read here. Either way the summary tables are
 * always derived from the very lists that get printed.
 */
final class AsistenciaDocumentoDataBuilder
{
    /** Statuses that are never counted under "other statuses" of a commissioned person. */
    private const ESTATUS_SIN_COLUMNA_PROPIA = [EstatusAsistencia::Presente, EstatusAsistencia::Comision];

    public function __construct(private readonly AsistenciaCapturaService $capturaService) {}

    public function paraEstacion(Estacion $estacion, CarbonInterface $fecha): OficioEstacionData
    {
        $roster = $this->capturaService->rosterFor($estacion, $fecha)
            ->sort(fn (Empleado $a, Empleado $b) => $this->comparar($a, $b));

        $filas = $roster
            ->map(fn (Empleado $empleado) => FilaPersonal::desde(
                $empleado,
                $empleado->registrosDiarios->first(),
                $estacion->nombre,
            ))
            ->values()
            ->all();

        return new OficioEstacionData($estacion, $fecha, $filas, ResumenAsistencia::desdeFilas($filas));
    }

    public function paraZona(CarbonInterface $fecha): OficioZonaData
    {
        $comisionesFuera = ComisionadoFuera::query()
            ->whereDate('fecha', $fecha)
            ->with(['empleado.registrosDiarios' => fn ($query) => $query->whereDate('fecha', $fecha)])
            ->get()
            // Several rows for one person the same day (two destinations) still
            // make ONE person: print and count them once, on their first row.
            ->unique('empleado_id')
            ->sort(fn (ComisionadoFuera $a, ComisionadoFuera $b) => $this->comparar($a->empleado, $b->empleado));

        $filasPorTipo = $this->filasDePersonalEnEstaciones($fecha, $comisionesFuera->pluck('empleado_id')->all());
        $visitantes = $this->visitantes($fecha);

        [$fueraEventuales, $fuera] = $comisionesFuera->partition(
            fn (ComisionadoFuera $comision) => $comision->empleado->tipo_plaza === TipoPlaza::Eventual,
        );

        return new OficioZonaData(
            fecha: $fecha,
            militares: $filasPorTipo['militares'],
            permanentes: $filasPorTipo['permanentes'],
            eventuales: $filasPorTipo['eventuales'],
            resumenMilitares: ResumenAsistencia::desdeFilas($filasPorTipo['militares']),
            resumenPermanentes: ResumenAsistencia::desdeFilas($filasPorTipo['permanentes']),
            resumenEventuales: ResumenAsistencia::desdeFilas($filasPorTipo['eventuales']),
            comisionadosOtrasCoordinaciones: $visitantes->map(fn (ComisionadoVisitante $v) => $this->filaVisitante($v))->values()->all(),
            comisionadosAOtrasAreas: $fuera->map(fn (ComisionadoFuera $c) => $this->filaFuera($c))->values()->all(),
            eventualesAOtrasAreas: $fueraEventuales->map(fn (ComisionadoFuera $c) => $this->filaFuera($c))->values()->all(),
            resumenComisionados: $this->resumenComisionados($fuera->count(), $fueraEventuales->count(), $visitantes->count(), $comisionesFuera),
        );
    }

    /**
     * Active personnel assigned to any station (Edificio Zonal Este
     * included), minus whoever is commissioned out that day, split by type
     * of plaza. Unclassified plazas are printed with the permanentes.
     *
     * @param  list<int>  $empleadosComisionadosIds
     * @return array{militares: list<FilaPersonal>, permanentes: list<FilaPersonal>, eventuales: list<FilaPersonal>}
     */
    private function filasDePersonalEnEstaciones(CarbonInterface $fecha, array $empleadosComisionadosIds): array
    {
        $empleados = Empleado::query()
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->whereNotNull('estacion_id')
            ->whereNotIn('id', $empleadosComisionadosIds)
            ->with([
                'estacion',
                'registrosDiarios' => fn ($query) => $query->whereDate('fecha', $fecha)->with('estacion'),
            ])
            ->get()
            ->sort(fn (Empleado $a, Empleado $b) => $this->comparar($a, $b));

        $grupos = ['militares' => [], 'permanentes' => [], 'eventuales' => []];

        foreach ($empleados as $empleado) {
            $registro = $empleado->registrosDiarios->first();
            $ubicacion = mb_strtoupper($registro?->estacion?->nombre ?? $empleado->estacion->nombre);
            $grupo = match ($empleado->tipo_plaza) {
                TipoPlaza::Militar => 'militares',
                TipoPlaza::Eventual => 'eventuales',
                default => 'permanentes',
            };

            $grupos[$grupo][] = FilaPersonal::desde($empleado, $registro, $ubicacion);
        }

        return $grupos;
    }

    /**
     * @return Collection<int, ComisionadoVisitante>
     */
    private function visitantes(CarbonInterface $fecha): Collection
    {
        return ComisionadoVisitante::query()
            ->whereDate('fecha', $fecha)
            ->with('estacion')
            ->orderBy('nombre')
            ->get();
    }

    private function filaVisitante(ComisionadoVisitante $visitante): FilaComisionado
    {
        $estacion = $visitante->estacion;
        $ubicacion = $estacion->is_operativa ? 'ESTACIÓN '.mb_strtoupper($estacion->nombre) : mb_strtoupper($estacion->nombre);

        return new FilaComisionado(
            direccion: mb_strtoupper((string) $visitante->direccion_origen),
            noEmpleado: (string) $visitante->no_trabajador,
            nombre: mb_strtoupper($visitante->nombre),
            estatus: 'PRESENTE',
            ubicacion: $ubicacion,
        );
    }

    /**
     * One of our own people commissioned out. If the same day they also
     * have a non-trivial daily status (e.g. vacaciones) it is printed first,
     * as in the real oficio: "VACACIONES 14 AL 21 SEP. 2026 COMISIÓN CGOFP".
     */
    private function filaFuera(ComisionadoFuera $comision): FilaComisionado
    {
        $empleado = $comision->empleado;
        $registro = $empleado->registrosDiarios->first();
        $partes = [];

        if ($this->tieneEstatusPropio($registro)) {
            $partes[] = FilaPersonal::desde($empleado, $registro, '')->estatusTextoZona();
        }

        $rango = FormatoFechaOficio::rangoCorto($comision->fecha_inicio, $comision->fecha_fin);
        $partes[] = trim('COMISIÓN '.mb_strtoupper((string) $comision->coordinacion_destino).' '.$rango);

        return new FilaComisionado(
            direccion: mb_strtoupper((string) config('asistencia_documentos.zona.direccion')),
            noEmpleado: (string) $empleado->no_empleado,
            nombre: mb_strtoupper((string) $empleado->nombre_completo),
            estatus: implode(' ', $partes),
            ubicacion: mb_strtoupper((string) $comision->ubicacion_destino),
        );
    }

    /**
     * Columns do not partition the people here: a commissioned person who
     * is also on vacation counts under both, but only once in the total.
     *
     * @param  Collection<int, ComisionadoFuera>|\Illuminate\Support\Collection<int, ComisionadoFuera>  $comisionesFuera
     */
    private function resumenComisionados(int $permanentes, int $eventuales, int $otras, $comisionesFuera): ResumenComisionados
    {
        $otrosEstatus = [];

        foreach ($comisionesFuera as $comision) {
            $registro = $comision->empleado->registrosDiarios->first();

            if ($this->tieneEstatusPropio($registro)) {
                $otrosEstatus[$registro->estatus->value] = ($otrosEstatus[$registro->estatus->value] ?? 0) + 1;
            }
        }

        return new ResumenComisionados($permanentes, $eventuales, $otras, $otrosEstatus, $permanentes + $eventuales + $otras);
    }

    private function tieneEstatusPropio(?RegistroDiario $registro): bool
    {
        return $registro !== null && ! in_array($registro->estatus, self::ESTATUS_SIN_COLUMNA_PROPIA, true);
    }

    /**
     * orden_oficio first (NULLs last), then name, so the printed order is
     * stable even for people not yet assigned a position.
     */
    private function comparar(Empleado $a, Empleado $b): int
    {
        $ordenA = $a->orden_oficio ?? PHP_INT_MAX;
        $ordenB = $b->orden_oficio ?? PHP_INT_MAX;

        return [$ordenA, $a->nombre_completo] <=> [$ordenB, $b->nombre_completo];
    }
}
