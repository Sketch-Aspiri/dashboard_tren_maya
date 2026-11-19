<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarEquipoRequest;
use App\Models\Equipo;
use App\Models\Estacion;
use App\Models\MenuGrupo;
use App\Models\User;
use App\Services\EquipoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

/** Equipos por estación (Administrador): qué estaciones los tienen, qué secciones usan y su vínculo con Tickets. */
class EquipoController extends Controller
{
    public function __construct(private readonly EquipoService $servicio) {}

    public function index(): View
    {
        $this->authorize('viewAny', Equipo::class);

        return view('equipos.index', [
            'equipos' => Equipo::query()->withCount('estaciones')->with('menuGrupos:id,nombre')->orderBy('id')->get(),
            'cuentas' => User::query()->whereNotNull('area')->selectRaw('area, count(*) as total')->groupBy('area')->pluck('total', 'area'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Equipo::class);

        return view('equipos.create', $this->opciones());
    }

    public function store(GuardarEquipoRequest $request): RedirectResponse
    {
        $this->authorize('create', Equipo::class);

        $equipo = $this->servicio->guardar(null, $request->validated());

        return $this->respuesta($equipo, __('Equipo creado.'));
    }

    public function edit(Equipo $equipo): View
    {
        $this->authorize('update', $equipo);

        return view('equipos.edit', $this->opciones() + ['equipo' => $equipo->load(['estaciones:id', 'menuGrupos:id'])]);
    }

    public function update(GuardarEquipoRequest $request, Equipo $equipo): RedirectResponse
    {
        $this->authorize('update', $equipo);

        $equipo = $this->servicio->guardar($equipo, $request->validated());

        return $this->respuesta($equipo, __('Equipo actualizado.'));
    }

    public function destroy(Equipo $equipo): RedirectResponse
    {
        $this->authorize('delete', $equipo);

        $this->servicio->eliminar($equipo);

        return redirect()->route('equipos.index')->with('status', __('Equipo eliminado. En Tickets se conserva su historial.'));
    }

    public function sincronizar(Equipo $equipo): RedirectResponse
    {
        $this->authorize('update', $equipo);

        return $this->respuesta($equipo, __('Equipo sincronizado con Tickets.'));
    }

    /** Vincula con Tickets; si Tickets no responde el equipo queda guardado y se avisa. */
    private function respuesta(Equipo $equipo, string $mensaje): RedirectResponse
    {
        $ok = $this->servicio->sincronizarConTickets($equipo);

        return redirect()->route('equipos.index')->with(
            $ok ? 'status' : 'warning',
            $ok ? $mensaje : __('El equipo se guardó, pero no se pudo sincronizar con Tickets. Usa «Sincronizar con Tickets» más tarde.'),
        );
    }

    /** @return array{estaciones: Collection<int, Estacion>, grupos: Collection<int, MenuGrupo>} */
    private function opciones(): array
    {
        return [
            'estaciones' => Estacion::query()->orderBy('orden')->get(),
            'grupos' => MenuGrupo::query()->orderBy('orden')->orderBy('id')->get(),
        ];
    }
}
