<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
    private const ESTACION_ROLE = 'Estación';

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

        $user->assignRole($data['role']);
        $this->logRoleChange($user, [], [$data['role']]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data  Validated UpdateUserRequest data.
     */
    public function update(User $user, array $data): User
    {
        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'estacion_id' => $this->resolveEstacionId($data),
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        $this->syncRoleIfChanged($user, $data['role']);

        return $user;
    }

    /**
     * The self-delete guard lives in UserPolicy::delete() — by the time
     * this runs, authorization has already confirmed the actor isn't
     * deleting their own account.
     */
    public function delete(User $user): void
    {
        $user->delete();
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
        if ($data['role'] !== self::ESTACION_ROLE) {
            return null;
        }

        return $data['estacion_id'] ?? null;
    }

    private function syncRoleIfChanged(User $user, string $role): void
    {
        $currentRoles = $user->getRoleNames()->all();

        if ($currentRoles === [$role]) {
            return;
        }

        $user->syncRoles([$role]);

        $this->logRoleChange($user, $currentRoles, [$role]);
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
