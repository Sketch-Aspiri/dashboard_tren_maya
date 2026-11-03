<?php

namespace App\Services;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Business logic for the "Gestión de usuarios" panel. Keeps
 * UserManagementController thin per .claude/rules/code-style.md.
 *
 * Role assignment (Spatie's roles live on a separate pivot table, not a
 * User column) is audited manually here rather than through User's
 * automatic LogsActivity diffing.
 */
final class UserManagementService
{
    /**
     * @param  array<string, mixed>  $data  Validated StoreUserRequest data.
     */
    public function create(array $data): User
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'estacion_id' => $this->resolveEstacionId($data),
            'email_verified_at' => now(),
        ]);

        $roles = array_values($data['roles']);
        $user->assignRole($roles);
        $this->logRoleChange($user, [], $roles);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data  Validated UpdateUserRequest data.
     */
    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data): User {
            $roles = array_values($data['roles']);

            $this->assertAdministradorSobrevive($user, $roles);

            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'estacion_id' => $this->resolveEstacionId($data),
            ]);

            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }

            $user->save();

            $this->syncRolesIfChanged($user, $roles);

            return $user;
        });
    }

    /**
     * Guarda defensiva (además del Form Request y la Policy): bloquea las
     * filas de los Administradores y revalida que, tras el cambio, siga
     * existiendo al menos uno. Evita que dos peticiones simultáneas dejen el
     * sistema sin quien gestione cuentas.
     *
     * @param  list<string>|null  $nuevosRoles  null = la cuenta se elimina.
     */
    private function assertAdministradorSobrevive(User $user, ?array $nuevosRoles): void
    {
        if ($nuevosRoles !== null && in_array(Roles::ADMINISTRADOR, $nuevosRoles, true)) {
            return;
        }

        $administradores = User::role(Roles::ADMINISTRADOR)->lockForUpdate()->pluck('users.id');

        if ($administradores->contains($user->id) && $administradores->count() <= 1) {
            throw ValidationException::withMessages([
                'roles' => __('No se puede dejar al sistema sin un Administrador.'),
            ]);
        }
    }

    /**
     * The self-delete guard lives in UserPolicy::delete() — by the time
     * this runs, authorization has already confirmed the actor isn't
     * deleting their own account.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->assertAdministradorSobrevive($user, null);

            $user->delete();
        });
    }

    /**
     * estacion_id is only ever persisted for role "Estación" — any value
     * submitted alongside a different role is ignored, never trusted from
     * client input regardless of what the form's Alpine toggle displayed.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveEstacionId(array $data): ?int
    {
        if (! in_array(Roles::ESTACION, $data['roles'], true)) {
            return null;
        }

        return $data['estacion_id'] ?? null;
    }

    /**
     * @param  list<string>  $roles
     */
    private function syncRolesIfChanged(User $user, array $roles): void
    {
        $currentRoles = $user->getRoleNames()->all();

        $nuevos = $roles;
        $actuales = $currentRoles;
        sort($nuevos);
        sort($actuales);

        if ($nuevos === $actuales) {
            return;
        }

        $user->syncRoles($roles);

        $this->logRoleChange($user, $currentRoles, $roles);
    }

    /**
     * @param  list<string>  $oldRoles
     * @param  list<string>  $newRoles
     */
    private function logRoleChange(User $user, array $oldRoles, array $newRoles): void
    {
        activity('user')
            ->performedOn($user)
            ->event('role_assigned')
            ->withProperties([
                'old' => ['roles' => $oldRoles],
                'attributes' => ['roles' => $newRoles],
            ])
            ->log('Rol de la cuenta actualizado.');
    }
}
