<?php

namespace App\Services;

use App\Enums\EmpleadoEstatus;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for the daily attendance capture screen (Fase 1 —
 * Control de Asistencia Diaria). Keeps AsistenciaCapturaController thin
 * per this project's layering convention.
 */
final class AsistenciaCapturaService
{
    /**
     * Resolves which Estacion a request targets.
     *
     * "Estación"-role accounts always capture their own station — any
     * client-supplied estación id is ignored, never trusted. "Administrador"
     * may target any station (including the non-operational "Edificio
     * Zonal Este") via the supplied id; when none is supplied, defaults to
     * the first operational station as a sensible landing page.
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
     * Active roster for the given estación, each empleado eager-loaded
     * with their RegistroDiario for the given fecha (if one already
     * exists). The caller defaults anyone without a row yet to
     * EstatusAsistencia::Presente.
     *
     * @return Collection<int, Empleado>
     */
    public function rosterFor(Estacion $estacion, CarbonInterface $fecha): Collection
    {
        return Empleado::query()
            ->where('estacion_id', $estacion->id)
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->orderBy('nombre_completo')
            ->with(['registrosDiarios' => fn ($query) => $query->whereDate('fecha', $fecha)])
            ->get();
    }

    /**
     * Bulk-upserts one RegistroDiario per submitted roster row.
     * estacion_id and registrado_por are always server-set here — never
     * taken from client input.
     *
     * Looks up the existing row (if any) via whereDate() rather than
     * RegistroDiario::updateOrCreate()'s literal attribute-equality search
     * — SQLite (used in tests) has no native DATE type and stores the
     * 'date'-cast column with a full "Y-m-d H:i:s" value, which would
     * otherwise never string-match a bare "Y-m-d" search value and cause
     * updateOrCreate() to insert a duplicate row (tripping the
     * empleado_id+fecha unique constraint) instead of updating in place.
     *
     * lockForUpdate() closes the resulting TOCTOU window: without it, two
     * near-simultaneous saves for the same empleado+fecha (realistic for a
     * shared station login, e.g. two tabs/devices, or an Administrador
     * correcting a record at the same moment) could both read "no existing
     * row" and both attempt an insert, colliding on the unique constraint
     * and surfacing as an uncaught 500 for the second submitter.
     *
     * @param  list<array{empleado_id: int, estatus: string, fecha_inicio?: ?string, fecha_fin?: ?string, notas?: ?string}>  $filas
     */
    public function guardar(Estacion $estacion, CarbonInterface $fecha, array $filas, User $actor): void
    {
        DB::transaction(function () use ($estacion, $fecha, $filas, $actor) {
            foreach ($filas as $fila) {
                $registro = RegistroDiario::query()
                    ->where('empleado_id', $fila['empleado_id'])
                    ->whereDate('fecha', $fecha)
                    ->lockForUpdate()
                    ->first() ?? new RegistroDiario([
                        'empleado_id' => $fila['empleado_id'],
                        'fecha' => $fecha,
                    ]);

                $registro->fill([
                    'estacion_id' => $estacion->id,
                    'estatus' => $fila['estatus'],
                    'fecha_inicio' => $fila['fecha_inicio'] ?? null,
                    'fecha_fin' => $fila['fecha_fin'] ?? null,
                    'notas' => $fila['notas'] ?? null,
                    'registrado_por' => $actor->id,
                ])->save();
            }
        });
    }

    /**
     * Whether every active empleado of the estación already has a
     * RegistroDiario for $fecha (same definition as resumenDelDia()'s
     * "capturado"). A station with no active roster is not "captured":
     * there is nothing to report, so no oficio can be generated for it.
     */
    public function estacionCapturada(Estacion $estacion, CarbonInterface $fecha): bool
    {
        $rosterIds = Empleado::query()
            ->where('estacion_id', $estacion->id)
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->pluck('id');

        if ($rosterIds->isEmpty()) {
            return false;
        }

        $capturados = RegistroDiario::query()
            ->whereIn('empleado_id', $rosterIds)
            ->whereDate('fecha', $fecha)
            ->distinct()
            ->count('empleado_id');

        return $capturados === $rosterIds->count();
    }

    /**
     * Every station (Edificio Zonal Este included) that has active roster
     * but has not captured all of it for $fecha yet. The zone oficio can
     * only be generated when this is empty. Two queries in total, merged in
     * PHP, so it does not grow with the number of stations.
     *
     * @return SupportCollection<int, Estacion>
     */
    public function estacionesPendientes(CarbonInterface $fecha): SupportCollection
    {
        $rosterPorEstacion = Empleado::query()
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->whereNotNull('estacion_id')
            ->get(['id', 'estacion_id'])
            ->groupBy('estacion_id');

        $capturados = RegistroDiario::query()
            ->whereDate('fecha', $fecha)
            ->pluck('empleado_id')
            ->unique();

        return Estacion::query()
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->filter(function (Estacion $estacion) use ($rosterPorEstacion, $capturados) {
                $ids = $rosterPorEstacion->get($estacion->id, collect())->pluck('id');

                return $ids->isNotEmpty() && $ids->diff($capturados)->isNotEmpty();
            })
            ->values();
    }

    /**
     * Today's (or the viewed fecha's) visiting personnel from other
     * coordinations for the given estación (Etapa 2).
     *
     * @return Collection<int, ComisionadoVisitante>
     */
    public function comisionadosVisitantesFor(Estacion $estacion, CarbonInterface $fecha): Collection
    {
        return ComisionadoVisitante::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $fecha)
            ->orderBy('nombre')
            ->get();
    }

    /**
     * Today's (or the viewed fecha's) own personnel commissioned out for
     * the given estación (Etapa 2).
     *
     * @return Collection<int, ComisionadoFuera>
     */
    public function comisionadosFueraFor(Estacion $estacion, CarbonInterface $fecha): Collection
    {
        return ComisionadoFuera::query()
            ->where('estacion_id', $estacion->id)
            ->whereDate('fecha', $fecha)
            ->with('empleado')
            ->get();
    }

    /**
     * Zona-wide "¿quién ya capturó hoy?" summary (Etapa 3). No separate
     * "completion marker" table exists — since guardar() is a single
     * atomic bulk upsert of the entire roster per estación+fecha, whether
     * a station "already captured" is derived live by comparing its
     * active roster against which of those empleados already have a
     * RegistroDiario for $fecha.
     *
     * Deliberately a fixed 3 queries total, not 1-per-station: the
     * operational estaciones lookup, one grouped roster query, and one
     * grouped "already captured" query, merged in PHP below — avoids an
     * N+1 across the 9 operational stations.
     *
     * @return SupportCollection<int, array{estacion: Estacion, total_roster: int, total_capturado: int, capturado: bool}>
     */
    public function resumenDelDia(CarbonInterface $fecha): SupportCollection
    {
        $estaciones = Estacion::query()
            ->where('is_operativa', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $estacionIds = $estaciones->pluck('id')->all();

        $rosterPorEstacion = Empleado::query()
            ->where('estatus', EmpleadoEstatus::Activo->value)
            ->whereIn('estacion_id', $estacionIds)
            ->get(['id', 'estacion_id'])
            ->groupBy('estacion_id');

        $todosLosEmpleadoIds = $rosterPorEstacion->flatten()->pluck('id')->all();

        $empleadoIdsCapturados = RegistroDiario::query()
            ->whereIn('empleado_id', $todosLosEmpleadoIds)
            ->whereDate('fecha', $fecha)
            ->pluck('empleado_id')
            ->unique();

        return $estaciones->map(function (Estacion $estacion) use ($rosterPorEstacion, $empleadoIdsCapturados) {
            $roster = $rosterPorEstacion->get($estacion->id, collect());
            $totalRoster = $roster->count();
            $totalCapturado = $roster->pluck('id')->intersect($empleadoIdsCapturados)->count();

            return [
                'estacion' => $estacion,
                'total_roster' => $totalRoster,
                'total_capturado' => $totalCapturado,
                // A station with zero active roster is neither "captured"
                // nor "pending" in a meaningful sense — treated as false
                // here, the view distinguishes it as "Sin personal" rather
                // than a red/pending badge.
                'capturado' => $totalRoster > 0 && $totalCapturado === $totalRoster,
            ];
        });
    }

    /**
     * Read-only drill-down for one estación+fecha (Etapa 3): the full
     * roster with each person's status, plus that day's comisionados
     * (visitantes/fuera). Reuses rosterFor()/comisionadosVisitantesFor()/
     * comisionadosFueraFor() rather than duplicating their queries.
     *
     * @return array{roster: Collection<int, Empleado>, comisionados_visitantes: Collection<int, ComisionadoVisitante>, comisionados_fuera: Collection<int, ComisionadoFuera>}
     */
    public function detalleDeEstacion(Estacion $estacion, CarbonInterface $fecha): array
    {
        return [
            'roster' => $this->rosterFor($estacion, $fecha),
            'comisionados_visitantes' => $this->comisionadosVisitantesFor($estacion, $fecha),
            'comisionados_fuera' => $this->comisionadosFueraFor($estacion, $fecha),
        ];
    }
}
