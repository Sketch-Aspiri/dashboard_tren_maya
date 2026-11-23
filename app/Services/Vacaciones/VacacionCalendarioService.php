<?php

namespace App\Services\Vacaciones;

use App\Models\Estacion;
use App\Models\VacacionDiaInhabil;
use App\Models\VacacionPlan;
use App\Models\VacacionSeleccion;
use App\Support\EstacionColores;
use Carbon\CarbonImmutable;

/**
 * Calendario mensual de las vacaciones que ya se eligieron (selecciones enviadas o autorizadas; los
 * borradores no se muestran). Se puede filtrar por persona (nombre o número), estación y estado.
 */
final class VacacionCalendarioService
{
    /**
     * @param  array{q?: string|null, estacion?: int|null, estado?: string|null}  $filtros
     * @param  int|null  $soloEstacion  restringe a una estación (cuentas Estación)
     * @return array{semanas: list<list<array<string, mixed>>>, personas: list<array<string, mixed>>, estaciones: list<array<string, mixed>>, total: int}
     */
    public function mes(int $anio, int $mes, array $filtros, ?int $soloEstacion): array
    {
        $primero = CarbonImmutable::create($anio, $mes, 1);
        $desde = $primero->startOfWeek();
        $hasta = $primero->endOfMonth()->endOfWeek();
        $estados = match ($filtros['estado'] ?? null) {
            VacacionPlan::AUTORIZADA => [VacacionPlan::AUTORIZADA],
            VacacionPlan::ENVIADA => [VacacionPlan::ENVIADA],
            default => [VacacionPlan::ENVIADA, VacacionPlan::AUTORIZADA],
        };
        $estacionId = $soloEstacion ?? ($filtros['estacion'] ?? null);

        $selecciones = VacacionSeleccion::query()
            ->with(['periodo', 'plan.empleado:id,no_empleado,nombre_completo', 'plan.estacion:id,nombre,orden'])
            ->whereHas('periodo', fn ($q) => $q->where('inicio', '<=', $hasta->toDateString())->where('fin', '>=', $desde->toDateString()))
            ->whereHas('plan', function ($q) use ($anio, $estados, $estacionId, $filtros) {
                $q->where('anio', $anio)->whereIn('estado', $estados)
                    ->when($estacionId, fn ($q) => $q->where('estacion_id', $estacionId))
                    ->when(filled($filtros['q'] ?? null), function ($q) use ($filtros) {
                        $busqueda = '%'.addcslashes(trim((string) $filtros['q']), '%_\\').'%';
                        $q->whereHas('empleado', fn ($e) => $e->where('nombre_completo', 'like', $busqueda)->orWhere('no_empleado', 'like', $busqueda));
                    });
            })->get();

        $personas = [];
        $porDia = [];

        foreach ($selecciones as $s) {
            $plan = $s->plan;
            $color = EstacionColores::para((int) ($plan->estacion->orden ?? 0), (int) $plan->estacion_id);
            $ficha = [
                'plan_id' => $plan->id,
                'nombre' => (string) $plan->empleado?->nombre_completo,
                'numero' => (string) $plan->empleado?->no_empleado,
                'estacion' => (string) $plan->estacion?->nombre,
                'orden' => (int) ($plan->estacion->orden ?? 0),
                'estado' => $plan->estado,
                'periodo' => $s->periodo->etiqueta(),
                'dias' => $s->periodo->dias,
                'color' => $color,
            ];
            $personas[$s->id] = $ficha;

            for ($d = $s->periodo->inicio->toImmutable(); $d->lte($s->periodo->fin); $d = $d->addDay()) {
                if ($d->between($desde, $hasta) && ! $d->isWeekend()) {
                    $porDia[$d->toDateString()][] = $ficha;
                }
            }
        }

        $inhabiles = VacacionDiaInhabil::query()->where('anio', $anio)->pluck('nombre', 'fecha')->mapWithKeys(fn ($n, $f) => [substr((string) $f, 0, 10) => $n])->all();
        $semanas = [];

        for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
            $fecha = $d->toDateString();
            $entradas = $porDia[$fecha] ?? [];
            usort($entradas, fn ($a, $b) => [$a['orden'], $a['nombre']] <=> [$b['orden'], $b['nombre']]);

            $semanas[intdiv($d->diffInDays($desde), 7)][] = [
                'fecha' => $fecha,
                'dia' => $d->day,
                'delMes' => $d->month === $mes,
                'finDeSemana' => $d->isWeekend(),
                'inhabil' => $inhabiles[$fecha] ?? null,
                'hoy' => $d->isSameDay(now('America/Cancun')),
                'entradas' => $entradas,
            ];
        }

        $personas = collect($personas)->unique(fn ($p) => $p['plan_id'].'|'.$p['periodo'])->sortBy(fn ($p) => sprintf('%03d|%s', $p['orden'], $p['nombre']))->values()->all();

        return [
            'semanas' => array_values($semanas),
            'personas' => $personas,
            'estaciones' => $this->leyenda($soloEstacion),
            'total' => count($personas),
        ];
    }

    /**
     * @return list<array{id: int, nombre: string, color: array{fondo: string, borde: string, texto: string}}>
     */
    private function leyenda(?int $soloEstacion): array
    {
        return Estacion::query()->when($soloEstacion, fn ($q) => $q->where('id', $soloEstacion))->orderBy('orden')->get(['id', 'nombre', 'orden'])
            ->map(fn (Estacion $e) => ['id' => $e->id, 'nombre' => $e->nombre, 'color' => EstacionColores::para((int) $e->orden, (int) $e->id)])->all();
    }
}
