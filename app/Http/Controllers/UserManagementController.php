<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\Estacion;
use App\Models\User;
use App\Services\UserManagementService;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;

/**
 * "Gestión de usuarios": Administrador-only panel to create/edit/delete
 * user accounts, superseding console-only provisioning for day-to-day
 * account management (the console commands are kept for bootstrapping).
 * Thin-controller shape per .claude/rules/code-style.md.
 */
class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['estacion', 'roles'])
            ->orderBy('name')
            ->paginate(15);

        return view('usuarios.index', ['users' => $users]);
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
