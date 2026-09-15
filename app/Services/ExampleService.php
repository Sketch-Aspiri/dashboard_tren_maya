<?php

namespace App\Services;

use App\Models\Example;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

/**
 * Business logic for the disposable reference CRUD module (see
 * CLAUDE.md). Keeps ExampleController thin per this project's layering
 * convention.
 */
final class ExampleService
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 100;

    /**
     * @param  array{name: string, value: ?string, status: string}  $data
     */
    public function create(array $data): Example
    {
        return Example::create($data);
    }

    /**
     * @param  array{name: string, value: ?string, status: string}  $data
     */
    public function update(Example $example, array $data): Example
    {
        $example->update($data);

        return $example;
    }

    public function delete(Example $example): void
    {
        $example->delete();
    }

    /**
     * Paginated, filterable listing for both the Blade index and the
     * examples.data JSON endpoint (api-conventions.md pagination/filter
     * params: q, sort, direction, page, per_page).
     */
    public function paginate(Request $request): LengthAwarePaginator
    {
        $perPage = min(
            (int) $request->integer('per_page', self::DEFAULT_PER_PAGE),
            self::MAX_PER_PAGE,
        );

        $sort = in_array($request->query('sort'), ['name', 'status', 'created_at'], true)
            ? $request->query('sort')
            : 'created_at';

        $direction = $request->query('direction') === 'asc' ? 'asc' : 'desc';

        return Example::query()
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q')->value().'%'))
            ->orderBy($sort, $direction)
            ->paginate($perPage <= 0 ? self::DEFAULT_PER_PAGE : $perPage)
            ->withQueryString();
    }
}
