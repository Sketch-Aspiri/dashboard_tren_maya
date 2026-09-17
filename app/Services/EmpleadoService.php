<?php

namespace App\Services;

use App\Models\Empleado;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Business logic for the "Agenda Zona Oriente" -> Personal directory.
 * Keeps EmpleadoController thin per this project's layering convention.
 */
final class EmpleadoService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * Columns allowed for sorting, per .claude/rules/api-conventions.md
     * (never trust a raw query param into orderBy).
     *
     * @var list<string>
     */
    private const SORTABLE_COLUMNS = ['orden_origen', 'nombre_completo', 'no_empleado', 'estacion_codigo', 'created_at'];

    private const DEFAULT_SORT = 'orden_origen';

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Empleado
    {
        return Empleado::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Empleado $empleado, array $data): Empleado
    {
        $empleado->update($data);

        return $empleado;
    }

    public function delete(Empleado $empleado): void
    {
        $empleado->delete();
    }

    /**
     * Paginated, filterable listing for both the Blade index and the
     * agenda.personal.data JSON endpoint (api-conventions.md
     * pagination/filter params: q, estatus, estacion_codigo, sort,
     * direction, page, per_page).
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $perPage = min(
            (int) $request->integer('per_page', self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        $sort = in_array($request->query('sort'), self::SORTABLE_COLUMNS, true)
            ? $request->query('sort')
            : self::DEFAULT_SORT;

        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';

        return Empleado::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q')->value().'%';

                $query->where(function ($inner) use ($term) {
                    $inner->where('nombre_completo', 'like', $term)
                        ->orWhere('no_empleado', 'like', $term)
                        ->orWhere('puesto', 'like', $term);
                });
            })
            ->when($request->filled('estatus'), fn ($query) => $query->where('estatus', $request->string('estatus')->value()))
            ->when($request->filled('estacion_codigo'), fn ($query) => $query->where('estacion_codigo', $request->string('estacion_codigo')->value()))
            // Static literal, no interpolated input — puts records with no
            // orden_origen (created by hand, not from the Excel import)
            // after the imported ones instead of first, regardless of DB engine.
            ->when($sort === self::DEFAULT_SORT, fn ($query) => $query->orderByRaw('orden_origen IS NULL'))
            ->orderBy($sort, $direction)
            ->when($sort === self::DEFAULT_SORT, fn ($query) => $query->orderBy('nombre_completo'))
            ->paginate($perPage <= 0 ? self::DEFAULT_PER_PAGE : $perPage)
            ->withQueryString();
    }
}
