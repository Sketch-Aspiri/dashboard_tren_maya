<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for the "Estadísticas" module (flujo de pasajeros /
 * boletos vendidos por estación). Keeps EstadisticaController thin per this
 * project's layering convention. Dummy/seeded data structure per CLAUDE.md
 * — the real KPI definitions are still pending the Jefe de Zona.
 */
final class EstadisticaDiariaService
{
    /**
     * Resolves which Estacion a request targets. Same pattern as
     * AsistenciaCapturaService::resolveEstacionObjetivo() — "Estación"-role
     * accounts always target their own station regardless of any
     * client-supplied id; "Administrador"/"Jefe de Zona" may target any
     * station via the supplied id, defaulting to the first operational
     * station when none is supplied.
     */
    public function resolveEstacionObjetivo(?User $user, ?int $estacionIdInput): ?Estacion
    {
        if ($user === null) {
            return null;
        }

        if ($user->hasRole('Estación')) {
            return $user->estacion;
        }

        if ($estacionIdInput !== null) {
            return Estacion::find($estacionIdInput);
        }

        return Estacion::query()
            ->where('is_operativa', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->first();
    }

    /**
     * Existing EstadisticaDiaria rows for the given estación+mes, keyed by
     * day of month (1..N) so the capture form can pre-fill each row.
     *
     * @return Collection<int, EstadisticaDiaria>
     */
    public function registrosDelMes(Estacion $estacion, int $anio, int $mes): Collection
    {
        return EstadisticaDiaria::query()
            ->where('estacion_id', $estacion->id)
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->get()
            ->keyBy(fn (EstadisticaDiaria $registro) => $registro->fecha->day);
    }

    /**
     * Bulk-upserts one EstadisticaDiaria row per day of the submitted
     * month that actually has captured data — days where both abordan and
     * boletos_vendidos are blank are skipped entirely (never persisted as
     * zero rows for un-captured/future days).
     *
     * Looks up the existing row (if any) via whereDate() rather than
     * EstadisticaDiaria::updateOrCreate()'s literal attribute-equality
     * search, and locks it with lockForUpdate() — same reasoning as
     * AsistenciaCapturaService::guardar() (SQLite date storage plus
     * closing a TOCTOU window on near-simultaneous saves).
     *
     * @param  list<array{dia: int, abordan?: ?int, boletos_vendidos?: ?int}>  $dias
     */
    public function guardarMes(Estacion $estacion, int $anio, int $mes, array $dias, User $actor): void
    {
        DB::transaction(function () use ($estacion, $anio, $mes, $dias, $actor) {
            foreach ($dias as $fila) {
                $this->guardarDia($estacion, $anio, $mes, $fila, $actor);
            }
        });
    }

    /**
     * Zona-wide annual summary (one row per estación, 12 monthly totals of
     * abordan/boletos_vendidos each). A "Estación"-role user is always
     * scoped to their own station here — never trusts the caller to have
     * already filtered, per the approved plan.
     *
     * @return SupportCollection<int, array{estacion: Estacion, abordan: array<int, int>, boletos_vendidos: array<int, int>}>
     */
    public function resumenAnual(User $user, int $anio): SupportCollection
    {
        $estacionesQuery = Estacion::query()
            ->where('is_operativa', true)
            ->orderBy('orden')
            ->orderBy('nombre');

        if ($user->hasRole('Estación')) {
            $estacionesQuery->where('id', $user->estacion_id);
        }

        $estaciones = $estacionesQuery->get();

        $registrosPorEstacion = EstadisticaDiaria::query()
            ->whereIn('estacion_id', $estaciones->pluck('id'))
            ->whereYear('fecha', $anio)
            ->get(['estacion_id', 'fecha', 'abordan', 'boletos_vendidos'])
            ->groupBy('estacion_id');

        return $estaciones->map(fn (Estacion $estacion) => $this->resumenAnualDeUnaEstacion(
            $estacion,
            $registrosPorEstacion->get($estacion->id, collect()),
        ));
    }

    /**
     * Monthly totals per estación (all operational estaciones, never
     * scoped) — used only by the dashboard KPI/chart, which is already
     * hidden from "Estación"-role users at the Gate level.
     *
     * @return SupportCollection<int, array{estacion: Estacion, abordan: int, boletos_vendidos: int}>
     */
    public function resumenMensualPorEstacion(int $anio, int $mes): SupportCollection
    {
        $estaciones = Estacion::query()
            ->where('is_operativa', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $registrosPorEstacion = EstadisticaDiaria::query()
            ->whereIn('estacion_id', $estaciones->pluck('id'))
            ->whereYear('fecha', $anio)
            ->whereMonth('fecha', $mes)
            ->get(['estacion_id', 'abordan', 'boletos_vendidos'])
            ->groupBy('estacion_id');

        return $estaciones->map(function (Estacion $estacion) use ($registrosPorEstacion) {
            $registros = $registrosPorEstacion->get($estacion->id, collect());

            return [
                'estacion' => $estacion,
                'abordan' => (int) $registros->sum('abordan'),
                'boletos_vendidos' => (int) $registros->sum('boletos_vendidos'),
            ];
        });
    }

    /**
     * @param  array{dia: int, abordan?: ?int, boletos_vendidos?: ?int}  $fila
     */
    private function guardarDia(Estacion $estacion, int $anio, int $mes, array $fila, User $actor): void
    {
        $abordan = $fila['abordan'] ?? null;
        $boletosVendidos = $fila['boletos_vendidos'] ?? null;

        if (blank($abordan) && blank($boletosVendidos)) {
            return;
        }

        $fecha = CarbonImmutable::create($anio, $mes, (int) $fila['dia'])->startOfDay();

        $registro = EstadisticaDiaria::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $fecha)
            ->lockForUpdate()
            ->first() ?? new EstadisticaDiaria([
                'estacion_id' => $estacion->id,
                'fecha' => $fecha,
            ]);

        $registro->fill([
            'estacion_id' => $estacion->id,
            'fecha' => $fecha,
            'abordan' => (int) ($abordan ?? 0),
            'boletos_vendidos' => (int) ($boletosVendidos ?? 0),
            'registrado_por' => $actor->id,
        ])->save();
    }

    /**
     * @param  SupportCollection<int, EstadisticaDiaria>  $registros
     * @return array{estacion: Estacion, abordan: array<int, int>, boletos_vendidos: array<int, int>}
     */
    private function resumenAnualDeUnaEstacion(Estacion $estacion, SupportCollection $registros): array
    {
        $abordanPorMes = array_fill(1, 12, 0);
        $boletosPorMes = array_fill(1, 12, 0);

        foreach ($registros as $registro) {
            $mes = $registro->fecha->month;
            $abordanPorMes[$mes] += $registro->abordan;
            $boletosPorMes[$mes] += $registro->boletos_vendidos;
        }

        return [
            'estacion' => $estacion,
            'abordan' => $abordanPorMes,
            'boletos_vendidos' => $boletosPorMes,
        ];
    }
}
