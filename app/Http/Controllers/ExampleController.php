<?php

namespace App\Http\Controllers;

use App\Enums\ExampleStatus;
use App\Http\Requests\StoreExampleRequest;
use App\Http\Requests\UpdateExampleRequest;
use App\Http\Resources\ExampleResource;
use App\Models\Example;
use App\Services\ExampleService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Disposable/adaptable reference CRUD controller (see CLAUDE.md). Kept
 * generic on purpose — adapt fields/requests/views once the real data
 * model lands instead of writing a new module.
 */
class ExampleController extends Controller
{
    public function __construct(private readonly ExampleService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Example::class);

        $examples = $this->service->paginate($request);

        return view('examples.index', [
            'examples' => $examples,
            'initialRows' => ExampleResource::collection($examples)->resolve($request),
            'statuses' => ExampleStatus::cases(),
        ]);
    }

    /**
     * JSON data endpoint for AJAX table refresh, per
     * .claude/rules/api-conventions.md response envelope.
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Example::class);

        $examples = $this->service->paginate($request);

        return response()->json([
            'success' => true,
            'data' => ExampleResource::collection($examples),
            'error' => null,
            'meta' => [
                'total' => $examples->total(),
                'page' => $examples->currentPage(),
                'per_page' => $examples->perPage(),
                'last_page' => $examples->lastPage(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Example::class);

        return view('examples.create', [
            'statuses' => ExampleStatus::cases(),
        ]);
    }

    public function store(StoreExampleRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return redirect()->route('examples.index')
            ->with('status', __('Registro creado correctamente.'));
    }

    public function show(Example $example): View
    {
        $this->authorize('view', $example);

        return view('examples.show', ['example' => $example]);
    }

    public function edit(Example $example): View
    {
        $this->authorize('update', $example);

        return view('examples.edit', [
            'example' => $example,
            'statuses' => ExampleStatus::cases(),
        ]);
    }

    public function update(UpdateExampleRequest $request, Example $example): RedirectResponse
    {
        $this->service->update($example, $request->validated());

        return redirect()->route('examples.index')
            ->with('status', __('Registro actualizado correctamente.'));
    }

    public function destroy(Example $example): RedirectResponse
    {
        $this->authorize('delete', $example);

        $this->service->delete($example);

        return redirect()->route('examples.index')
            ->with('status', __('Registro eliminado correctamente.'));
    }
}
