<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\Vacacionista;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consultas del "Rol de vacaciones" (Agenda Zona Oriente). Solo lectura: los
 * datos entran por app:import-rol-vacaciones.
 */
final class RolVacacionesService
{
    private const PER_PAGE = 25;

    /**
     * @return list<int> Años con rol cargado, del más reciente al más antiguo.
     */
    public function aniosDisponibles(): array
    {
        return Vacacionista::query()
            ->distinct()
            ->orderByDesc('anio')
            ->pluck('anio')
            ->map(fn ($anio) => (int) $anio)
            ->all();
    }

    /**
     * @param  array{q?: ?string, estacion_id?: ?int, mes?: ?int}  $filtros
     * @return LengthAwarePaginator<int, Vacacionista>
     */
    public function paginate(int $anio, array $filtros): LengthAwarePaginator
    {
        return Vacacionista::query()
            ->with(['estacion', 'periodos'])
            ->where('anio', $anio)
            ->when($filtros['q'] ?? null, fn (Builder $query, string $q) => $this->buscar($query, $q))
            ->when($filtros['estacion_id'] ?? null, fn (Builder $query, int $estacionId) => $query->where('estacion_id', $estacionId))
            ->when($filtros['mes'] ?? null, fn (Builder $query, int $mes) => $this->deVacacionesEnElMes($query, $anio, $mes))
            ->orderBy(Estacion::query()->select('orden')->whereColumn('estaciones.id', 'vacacionistas.estacion_id'))
            ->orderBy('nombre_completo')
            ->paginate(self::PER_PAGE)
            ->withQueryString();
    }

    /**
     * @param  Builder<Vacacionista>  $query
     */
    private function buscar(Builder $query, string $q): void
    {
        $term = '%'.addcslashes($q, '%_\\').'%';

        $query->where(function (Builder $inner) use ($term) {
            $inner->where('nombre_completo', 'like', $term)
                ->orWhere('no_empleado', 'like', $term)
                ->orWhere('denominacion_puesto', 'like', $term);
        });
    }

    /**
     * Personas con algún periodo que se traslapa con el mes indicado.
     *
     * @param  Builder<Vacacionista>  $query
     */
    private function deVacacionesEnElMes(Builder $query, int $anio, int $mes): void
    {
        $inicioMes = CarbonImmutable::create($anio, $mes, 1);

        $query->whereHas('periodos', fn (Builder $periodos) => $periodos
            ->whereDate('fecha_inicio', '<=', $inicioMes->endOfMonth()->toDateString())
            ->whereDate('fecha_termino', '>=', $inicioMes->toDateString()));
    }
}
