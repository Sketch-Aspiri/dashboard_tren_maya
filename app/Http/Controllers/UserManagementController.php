<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUsersRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Estacion;
use App\Models\User;
use App\Services\UserManagementService;
use App\Support\Areas;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Spatie\Permission\Models\Role;

/**
 * "Gestión de usuarios": Administrador-only panel to create/edit/delete
 * user accounts, superseding console-only provisioning for day-to-day
 * account management (the console commands are kept for bootstrapping).
 * Thin-controller shape per .claude/rules/code-style.md.
 */
class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $service) {}

    private const ORDENES = ['nombre', 'rol', 'estacion', 'area'];

    public function index(IndexUsersRequest $request): View
    {
        $this->authorize('viewAny', User::class);

        $orden = in_array($request->validated('orden'), self::ORDENES, true) ? $request->validated('orden') : 'nombre';
        $dir = $request->validated('dir') === 'desc' ? 'desc' : 'asc';
        $rol = (string) $request->validated('rol', '');
        $estacion = (string) $request->validated('estacion', '');
        $area = (string) $request->validated('area', '');
        $q = trim((string) $request->validated('q', ''));
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);

        $users = User::query()
            ->select('users.*')
            ->with(['estacion', 'roles'])
            ->when($rol !== '', fn ($query) => $query->role($rol))
            ->when($estacion === 'sin', fn ($query) => $query->whereNull('users.estacion_id'))
            ->when(ctype_digit($estacion), fn ($query) => $query->where('users.estacion_id', (int) $estacion))
            ->when($area === 'sin', fn ($query) => $query->whereNull('users.area'))
            ->when(isset(Areas::NOMBRES[$area]), fn ($query) => $query->where('users.area', $area))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->where('users.name', 'like', '%'.$like.'%')
                ->orWhere('users.email', 'like', '%'.$like.'%')));

        match ($orden) {
            'rol' => $users->orderBy(
                Role::query()->select('roles.name')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->orderBy('roles.name')->limit(1),
                $dir,
            ),
            'estacion' => $users->orderBy(
                Estacion::query()->select('estaciones.orden')->whereColumn('estaciones.id', 'users.estacion_id'),
                $dir,
            ),
            'area' => $users->orderBy('users.area', $dir),
            default => null,
        };
        $users->orderBy('users.name', $orden === 'nombre' ? $dir : 'asc');

        return view('usuarios.index', [
            'users' => $users->paginate(15)->withQueryString(),
            'roles' => RoleSeeder::ROLES,
            'estaciones' => $this->estaciones(),
            'filtros' => compact('rol', 'estacion', 'area', 'q', 'orden', 'dir'),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', User::class);

        return view('usuarios.create', [
            'roles' => RoleSeeder::ROLES,
            'estaciones' => $this->estaciones(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $this->service->create($request->validated());

        return redirect()->route('usuarios.index')
            ->with('status', __('Cuenta creada correctamente.'));
    }

    public function edit(User $user): View
    {
        $this->authorize('update', $user);

        return view('usuarios.edit', [
            'user' => $user,
            'roles' => RoleSeeder::ROLES,
            'estaciones' => $this->estaciones(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->service->update($user, $request->validated());

        return redirect()->route('usuarios.index')
            ->with('status', __('Cuenta actualizada correctamente.'));
    }

    public function destroy(User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $this->service->delete($user);

        return redirect()->route('usuarios.index')
            ->with('status', __('Cuenta eliminada correctamente.'));
    }

    /**
     * Every station in the catalog, including "Edificio Zonal Este": an
     * Estación account can be tied to the zone office too.
     *
     * @return Collection<int, Estacion>
     */
    private function estaciones(): Collection
    {
        return Estacion::query()
            ->orderBy('orden')
            ->get();
    }
}
