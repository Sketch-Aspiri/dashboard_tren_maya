<?php

namespace App\Http\Controllers;

use App\Enums\EmpleadoEstatus;
use App\Http\Requests\StoreEmpleadoRequest;
use App\Http\Requests\UpdateEmpleadoRequest;
use App\Http\Resources\EmpleadoListResource;
use App\Http\Resources\EmpleadoResource;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Services\EmpleadoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * "Agenda Zona Oriente" -> Personal: real personnel directory CRUD.
 * Thin-controller shape per .claude/rules/code-style.md.
 */
class EmpleadoController extends Controller
{
    public function __construct(private readonly EmpleadoService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Empleado::class);

        $empleados = $this->service->paginate($request);

        return view('agenda.personal.index', [
            'empleados' => $empleados,
            'initialRows' => EmpleadoListResource::collection($empleados)->resolve($request),
            'initialMeta' => [
                'total' => $empleados->total(),
                'page' => $empleados->currentPage(),
                'per_page' => $empleados->perPage(),
                'last_page' => $empleados->lastPage(),
            ],
            'statuses' => EmpleadoEstatus::cases(),
        ]);
    }

    /**
     * JSON data endpoint for AJAX table refresh, per
     * .claude/rules/api-conventions.md response envelope.
     *
     * Uses the slim EmpleadoListResource (not EmpleadoResource) — the
     * listing only ever displays a handful of directory columns, so the
     * full record (CURP, RFC, NSS, domicilio, etc.) has no reason to be
     * fetched/embedded for every row on every search/filter change. The
     * full record is only served by `show()`.
     */
    public function data(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Empleado::class);

        $empleados = $this->service->paginate($request);

        return response()->json([
            'success' => true,
            'data' => EmpleadoListResource::collection($empleados),
            'error' => null,
            'meta' => [
                'total' => $empleados->total(),
                'page' => $empleados->currentPage(),
                'per_page' => $empleados->perPage(),
                'last_page' => $empleados->lastPage(),
            ],
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Empleado::class);

        return view('agenda.personal.create', [
            'statuses' => EmpleadoEstatus::cases(),
            'estaciones' => $this->estacionesForSelect(),
        ]);
    }

    public function store(StoreEmpleadoRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return redirect()->route('agenda.personal.index')
            ->with('status', __('Registro creado correctamente.'));
    }

    public function show(Empleado $empleado): View
    {
        $this->authorize('view', $empleado);

        return view('agenda.personal.show', ['empleado' => $empleado->load('estacion')]);
    }

    public function edit(Empleado $empleado): View
    {
        $this->authorize('update', $empleado);

        return view('agenda.personal.edit', [
            'empleado' => $empleado,
            'statuses' => EmpleadoEstatus::cases(),
            'estaciones' => $this->estacionesForSelect(),
        ]);
    }

    public function update(UpdateEmpleadoRequest $request, Empleado $empleado): RedirectResponse
    {
        $this->service->update($empleado, $request->validated());

        return redirect()->route('agenda.personal.index')
            ->with('status', __('Registro actualizado correctamente.'));
    }

    public function destroy(Empleado $empleado): RedirectResponse
    {
        $this->authorize('delete', $empleado);

        $this->service->delete($empleado);

        return redirect()->route('agenda.personal.index')
            ->with('status', __('Registro eliminado correctamente.'));
    }

    /**
     * Full station catalog (operational and non-operational, e.g.
     * "Edificio Zonal Este") for the create/edit form's estacion_id
     * select — an Administrador must be able to link an empleado to any
     * station, not only the operational ones. Ordered per the
     * orden/nombre convention used elsewhere (see
     * AsistenciaCapturaService::resolveEstacionObjetivo()).
     *
     * @return Collection<int, Estacion>
     */
    private function estacionesForSelect(): Collection
    {
        return Estacion::query()->orderBy('orden')->orderBy('nombre')->get();
    }
}
