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
 * oficio generators print. Station and zone oficios share one structure
 * (the station's is the zone's restricted to its own people). The summary
 * tables are always derived from the very lists that get printed.
 */
final class AsistenciaDocumentoDataBuilder
{
    /** Statuses that are never counted under "other statuses" of a commissioned person. */
    private const ESTATUS_SIN_COLUMNA_PROPIA = [EstatusAsistencia::Presente, EstatusAsistencia::Comision];

    public function __construct(private readonly AsistenciaCapturaService $capturaService) {}

    /**
     * Same document as the zone's, restricted to one station: its own
     * personnel, the people it sent on commission and the visitors it received.
     */
    public function paraEstacion(Estacion $estacion, CarbonInterface $fecha): OficioZonaData
    {
        return $this->paraZona($fecha, $estacion);
    }

    public function paraZona(CarbonInterface $fecha, ?Estacion $estacion = null): OficioZonaData
    {
        $comisionesFuera = ComisionadoFuera::query()
            ->whereDate('fecha', $fecha)
            ->when($estacion !== null, fn ($query) => $query->whereHas(
                'empleado',
                fn ($empleado) => $empleado->where('estacion_id', $estacion->id),
            ))
            ->with(['empleado.registrosDiarios' => fn ($query) => $query->whereDate('fecha', $fecha)])
            ->get()
            // Several rows for one person the same day (two destinations) still
            // make ONE person: print and count them once, on their first row.
            ->unique('empleado_id')
            ->sort(fn (ComisionadoFuera $a, ComisionadoFuera $b) => $this->comparar($a->empleado, $b->empleado));

        $filasPorTipo = $this->filasDePersonalEnEstaciones($fecha, $comisionesFuera->pluck('empleado_id')->all(), $estacion);
        $visitantes = $this->visitantes($fecha, $estacion);

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
            resumenComisionados: $this->resumenComisionados($comisionesFuera, $visitantes->count()),
            estacion: $estacion,
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
    private function filasDePersonalEnEstaciones(CarbonInterface $fecha, array $empleadosComisionadosIds, ?Estacion $estacion): array
    {
        $empleados = Empleado::query()
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->when(
                $estacion !== null,
                fn ($query) => $query->where('estacion_id', $estacion->id),
                fn ($query) => $query->whereNotNull('estacion_id'),
            )
            ->whereNotIn('id', $empleadosComisionadosIds)
            ->with([
                'estacion',
                'registrosDiarios' => fn ($query) => $query->whereDate('fecha', $fecha)->with('estacion'),
            ])
            ->get()
            ->sort(fn (Empleado $a, Empleado $b) => $this->compararPorEstacion($a, $b));

        $grupos = ['militares' => [], 'permanentes' => [], 'eventuales' => []];

        foreach ($empleados as $empleado) {
            $registro = $empleado->registrosDiarios->first();
            $ubicacion = mb_strtoupper($this->estacionUbicacion($empleado)->nombre);
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
    private function visitantes(CarbonInterface $fecha, ?Estacion $estacion): Collection
    {
        return ComisionadoVisitante::query()
            ->whereDate('fecha', $fecha)
            ->when($estacion !== null, fn ($query) => $query->where('estacion_id', $estacion->id))
            ->with('estacion')
            ->orderBy('nombre')
            ->get();
    }

    private function filaVisitante(ComisionadoVisitante $visitante): FilaComisionado
    {
        $origen = mb_strtoupper((string) $visitante->direccion_origen);

        return new FilaComisionado(
            direccion: $origen,
            noEmpleado: (string) $visitante->no_trabajador,
            nombre: mb_strtoupper($visitante->nombre),
            estatus: trim('PRESENTE
COMISIÓN '.$origen),
            ubicacion: mb_strtoupper($visitante->estacion->nombre),
        );
    }

    /**
     * One of our own people commissioned out, printed on two lines like the
     * Excel: their own daily status (when it is not Presente/Comisión) over
     * "COMISIÓN <destino> <periodo>", e.g. "DESCANSO / COMISIÓN CGGIF"; with
     * no status of their own: "COMISIÓN / CGOFP".
     */
    private function filaFuera(ComisionadoFuera $comision): FilaComisionado
    {
        $empleado = $comision->empleado;
        $registro = $empleado->registrosDiarios->first();
        $destino = trim(mb_strtoupper((string) $comision->coordinacion_destino).' '.FormatoFechaOficio::rangoCorto($comision->fecha_inicio, $comision->fecha_fin));

        $estatus = $this->tieneEstatusPropio($registro)
            ? FilaPersonal::desde($empleado, $registro, '')->estatusTexto().'
COMISIÓN '.$destino
            : 'COMISIÓN
'.$destino;

        return new FilaComisionado(
            direccion: mb_strtoupper((string) config('asistencia_documentos.zona.direccion')),
            noEmpleado: (string) $empleado->no_empleado,
            nombre: mb_strtoupper((string) $empleado->nombre_completo),
            estatus: trim($estatus),
            ubicacion: mb_strtoupper((string) $comision->ubicacion_destino),
        );
    }

    /**
     * Table d of the oficio, like the Excel: every commissioned person counts
     * ONCE. Someone with a daily status of their own (descanso, vacaciones…)
     * counts under it; otherwise under the commission column of their kind
     * (permanente or eventual). Visitors from other coordinations always go
     * under "otra coord.".
     *
     * @param  Collection<int, ComisionadoFuera>|\Illuminate\Support\Collection<int, ComisionadoFuera>  $comisionesFuera
     */
    private function resumenComisionados($comisionesFuera, int $visitantes): ResumenComisionados
    {
        $otrosEstatus = [];
        $permanentes = 0;
        $eventuales = 0;

        foreach ($comisionesFuera as $comision) {
            $registro = $comision->empleado->registrosDiarios->first();

            if ($this->tieneEstatusPropio($registro)) {
                $otrosEstatus[$registro->estatus->value] = ($otrosEstatus[$registro->estatus->value] ?? 0) + 1;
            } elseif ($comision->empleado->tipo_plaza === TipoPlaza::Eventual) {
                $eventuales++;
            } else {
                $permanentes++;
            }
        }

        return new ResumenComisionados($permanentes, $eventuales, $visitantes, $otrosEstatus, $comisionesFuera->count() + $visitantes);
    }

    private function tieneEstatusPropio(?RegistroDiario $registro): bool
    {
        return $registro !== null && ! in_array($registro->estatus, self::ESTATUS_SIN_COLUMNA_PROPIA, true);
    }

    private function esDirectivo(Empleado $empleado): bool
    {
        return FilaPersonal::esDirectivo($empleado->puesto);
    }

    /**
     * orden_oficio first (NULLs last), then name, so the printed order is
     * stable even for people not yet assigned a position.
     */
    private function comparar(Empleado $a, Empleado $b): int
    {
        $ordenA = $a->orden_oficio ?? PHP_INT_MAX;
        $ordenB = $b->orden_oficio ?? PHP_INT_MAX;

        // Without an explicit position, the station manager heads the list (as in the Excel).
        return [$ordenA, $this->esDirectivo($a) ? 0 : 1, $a->nombre_completo]
            <=> [$ordenB, $this->esDirectivo($b) ? 0 : 1, $b->nombre_completo];
    }

    /**
     * Zone oficio ordering: every station's people print together (per
     * Estacion.orden, the same geographic/route order used everywhere else
     * — Bacalar, Chetumal, ... Edificio Zonal Este last), and only within a
     * station does orden_oficio/name break ties. Without the station
     * grouping the list interleaves stations, which is what the printed
     * oficio must never do.
     */
    private function compararPorEstacion(Empleado $a, Empleado $b): int
    {
        // estaciones.orden is nullable at the schema level even though
        // EstacionSeeder always sets it for the 10 fixed rows — guarded the
        // same defensive way as comparar() guards orden_oficio, so a
        // never-expected null sorts predictably last instead of silently
        // jumping first.
        $ordenEstacionA = $this->estacionUbicacion($a)->orden ?? PHP_INT_MAX;
        $ordenEstacionB = $this->estacionUbicacion($b)->orden ?? PHP_INT_MAX;

        if ($ordenEstacionA !== $ordenEstacionB) {
            return $ordenEstacionA <=> $ordenEstacionB;
        }

        return $this->comparar($a, $b);
    }

    /**
     * The station a person actually prints under for the day: their
     * RegistroDiario's estación if today's capture points somewhere else
     * (e.g. a temporary reassignment), otherwise their own estación_id.
     * Mirrors the fallback already used for the printed "ubicación" label,
     * so the sort key and the label always agree.
     */
    private function estacionUbicacion(Empleado $empleado): Estacion
    {
        return $empleado->registrosDiarios->first()?->estacion ?? $empleado->estacion;
    }
}
