<?php

namespace App\Services;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Http\Resources\ComisionadoFueraCapturaResource;
use App\Http\Resources\ComisionadoVisitanteCapturaResource;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\Documentos\FilaPersonal;
use App\Services\Documentos\FormatoFechaOficio;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Plain-array state of the daily capture screen, handed to its Alpine
 * component (resources/js/asistencia/captura.js). Everything the live preview
 * needs travels here so it can redraw on every keystroke, before anything is
 * saved.
 */
final class CapturaAsistenciaEstado
{
    /** How far back to look for the last day that had commissioned people. */
    private const DIAS_ATRAS_COMISIONADOS = 7;

    private const MAX_SUGERENCIAS = 40;

    /**
     * @param  Collection<int, Empleado>  $roster
     * @param  Collection<int, ComisionadoVisitante>  $visitantes
     * @param  Collection<int, ComisionadoFuera>  $fuera
     * @param  array<int|string, array<string, mixed>>  $registrosPrevios  old('registros') after a failed save
     * @param  array{numero: string, firmante_cargo: string, firmante_nombre: string}  $oficio
     * @return array<string, mixed>
     */
    public function construir(
        Estacion $estacion,
        CarbonInterface $fecha,
        Collection $roster,
        Collection $visitantes,
        Collection $fuera,
        array $registrosPrevios,
        array $oficio,
    ): array {
        $continuidades = $this->continuidades($roster, $fecha);

        return [
            'fecha' => $fecha->toDateString(),
            'estacion' => [
                'id' => $estacion->id,
                'nombre' => $estacion->nombre,
                'is_operativa' => (bool) $estacion->is_operativa,
            ],
            'catalogo' => array_map(fn (EstatusAsistencia $estatus) => [
                'valor' => $estatus->value,
                'etiqueta' => $estatus->label(),
                'oficio' => $estatus->etiquetaOficio(),
            ], EstatusAsistencia::cases()),
            'personas' => $roster->map(fn (Empleado $empleado) => $this->persona(
                $empleado,
                $registrosPrevios[$empleado->id] ?? null,
                $continuidades[$empleado->id] ?? null,
            ))->values()->all(),
            'fuera' => $fuera->map(fn (ComisionadoFuera $c) => $this->filaFuera($c))->values()->all(),
            'visitantes' => $visitantes->map(fn (ComisionadoVisitante $v) => $this->filaVisitante($v))->values()->all(),
            'anterior' => [
                'fuera' => $this->fueraAnteriores($estacion, $fecha, $roster),
                'visitantes' => $this->visitantesAnteriores($estacion, $fecha),
            ],
            'sugerencias' => $this->sugerencias($estacion),
            'formato' => config('asistencia_documentos.formato'),
            'direccion' => mb_strtoupper((string) config('asistencia_documentos.zona.direccion')),
            'oficio' => [
                'numero' => $oficio['numero'],
                'cargo' => $oficio['firmante_cargo'],
                'nombre' => $oficio['firmante_nombre'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filaFuera(ComisionadoFuera $comision): array
    {
        return ComisionadoFueraCapturaResource::make($comision)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function filaVisitante(ComisionadoVisitante $visitante): array
    {
        return ComisionadoVisitanteCapturaResource::make($visitante)->resolve();
    }

    /**
     * @param  array<string, mixed>|null  $previo
     * @param  array<string, mixed>|null  $continuidad
     * @return array<string, mixed>
     */
    private function persona(Empleado $empleado, ?array $previo, ?array $continuidad): array
    {
        $registro = $empleado->registrosDiarios->first();

        return [
            'id' => $empleado->id,
            'no_empleado' => (string) $empleado->no_empleado,
            'nombre' => (string) $empleado->nombre_completo,
            'puesto' => (string) $empleado->puesto,
            'grupo' => match ($empleado->tipo_plaza) {
                TipoPlaza::Militar => 'militares',
                TipoPlaza::Eventual => 'eventuales',
                default => 'permanentes',
            },
            'destacada' => FilaPersonal::esDirectivo($empleado->puesto),
            // Nobody defaults to "Presente": each person is picked explicitly.
            'estatus' => (string) ($previo['estatus'] ?? $registro?->estatus?->value ?? ''),
            'fecha_inicio' => (string) ($previo['fecha_inicio'] ?? $registro?->fecha_inicio?->toDateString() ?? ''),
            'fecha_fin' => (string) ($previo['fecha_fin'] ?? $registro?->fecha_fin?->toDateString() ?? ''),
            'notas' => (string) ($previo['notas'] ?? $registro?->notas ?? ''),
            'continuidad' => $registro === null ? $continuidad : null,
        ];
    }

    /**
     * Periods captured on an earlier day that still cover $fecha (vacations,
     * medical leave…), offered to the person capturing as a one-click
     * suggestion: never applied on their behalf.
     *
     * @param  Collection<int, Empleado>  $roster
     * @return array<int, array<string, string>>
     */
    private function continuidades(Collection $roster, CarbonInterface $fecha): array
    {
        return RegistroDiario::query()
            ->whereIn('empleado_id', $roster->pluck('id'))
            ->whereDate('fecha', '<', $fecha)
            ->whereDate('fecha_fin', '>=', $fecha)
            ->orderBy('fecha')
            ->get()
            ->keyBy('empleado_id') // ordered by fecha: the most recent row wins
            ->map(fn (RegistroDiario $registro) => [
                'estatus' => $registro->estatus->value,
                'etiqueta' => $registro->estatus->label(),
                'fecha_inicio' => $registro->fecha_inicio?->toDateString() ?? '',
                'fecha_fin' => $registro->fecha_fin->toDateString(),
                'notas' => (string) $registro->notas,
                'hasta' => FormatoFechaOficio::corta($registro->fecha_fin),
            ])
            ->all();
    }

    /**
     * Commissions of the last day that had any, still in force on $fecha.
     * Rows are per-day, so without this they would be retyped every morning.
     *
     * @param  Collection<int, Empleado>  $roster
     * @return list<array<string, mixed>>
     */
    private function fueraAnteriores(Estacion $estacion, CarbonInterface $fecha, Collection $roster): array
    {
        $ultimoDia = $this->ultimoDiaCon(ComisionadoFuera::query()->where('estacion_id', $estacion->id), $fecha);

        if ($ultimoDia === null) {
            return [];
        }

        return ComisionadoFuera::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $ultimoDia)
            ->whereIn('empleado_id', $roster->pluck('id'))
            ->where(fn ($query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $fecha))
            ->get()
            ->unique('empleado_id')
            ->map(fn (ComisionadoFuera $c) => $this->filaFuera($c))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function visitantesAnteriores(Estacion $estacion, CarbonInterface $fecha): array
    {
        $ultimoDia = $this->ultimoDiaCon(ComisionadoVisitante::query()->where('estacion_id', $estacion->id), $fecha);

        if ($ultimoDia === null) {
            return [];
        }

        return ComisionadoVisitante::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $ultimoDia)
            ->where(fn ($query) => $query->whereNull('fecha_fin')->orWhereDate('fecha_fin', '>=', $fecha))
            ->orderBy('nombre')
            ->get()
            ->map(fn (ComisionadoVisitante $v) => $this->filaVisitante($v))
            ->values()
            ->all();
    }

    /**
     * @param  Builder<Model>  $consulta
     */
    private function ultimoDiaCon($consulta, CarbonInterface $fecha): ?string
    {
        $ultimo = $consulta
            ->whereDate('fecha', '<', $fecha)
            ->whereDate('fecha', '>=', $fecha->copy()->subDays(self::DIAS_ATRAS_COMISIONADOS))
            ->max('fecha');

        return $ultimo === null ? null : substr((string) $ultimo, 0, 10);
    }

    /**
     * Autocomplete lists. Coordinations and places are catalog values shared
     * by the whole zone; the visitors' names stay scoped to the station.
     *
     * @return array<string, mixed>
     */
    private function sugerencias(Estacion $estacion): array
    {
        $base = config('asistencia_documentos.captura');

        return [
            'coordinaciones' => $this->distintos(ComisionadoFuera::class, 'coordinacion_destino', $base['coordinaciones']),
            'ubicaciones' => $this->distintos(ComisionadoFuera::class, 'ubicacion_destino', $base['ubicaciones']),
            'origenes' => $this->distintos(ComisionadoVisitante::class, 'direccion_origen', $base['coordinaciones']),
            'visitantes' => ComisionadoVisitante::query()
                ->where('estacion_id', $estacion->id)
                ->orderByDesc('fecha')
                ->limit(self::MAX_SUGERENCIAS * 3)
                ->get(['nombre', 'no_trabajador', 'direccion_origen'])
                ->unique(fn (ComisionadoVisitante $v) => mb_strtoupper($v->nombre))
                ->take(self::MAX_SUGERENCIAS)
                ->map(fn (ComisionadoVisitante $v) => [
                    'nombre' => (string) $v->nombre,
                    'no_trabajador' => (string) $v->no_trabajador,
                    'direccion_origen' => (string) $v->direccion_origen,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  class-string<Model>  $modelo
     * @param  list<string>  $base
     * @return list<string>
     */
    private function distintos(string $modelo, string $columna, array $base): array
    {
        /** @var SupportCollection<int, string> $usados */
        $usados = $modelo::query()
            ->whereNotNull($columna)
            ->where($columna, '!=', '')
            ->distinct()
            ->limit(self::MAX_SUGERENCIAS)
            ->pluck($columna);

        return collect($base)
            ->merge($usados)
            ->unique(fn (string $valor) => mb_strtoupper(trim($valor)))
            ->sort()
            ->values()
            ->all();
    }
}
