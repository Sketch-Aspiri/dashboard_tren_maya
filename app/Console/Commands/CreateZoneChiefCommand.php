<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Interactively provisions the single real Jefe de Zona account for this
 * environment. Deliberately NOT part of `migrate --seed`: the project's
 * only real user must never be created with a known/default password (see
 * CLAUDE.md and .claude/rules/server-conventions.md). Run this once per
 * environment (local, staging, production) after `migrate --seed`.
 */
class CreateZoneChiefCommand extends Command
{
    /**
     * The single shared account carries both roles: "Jefe de Zona" (the
     * dashboard owner) and "Administrador" (captures attendance for every
     * station, manages sections/users). Decision of the Jefe de Zona,
     * oct 2026: one administrator account shared by the whole team, no
     * per-station accounts for now.
     *
     * @var list<string>
     */
    private const ROLE_NAMES = ['Jefe de Zona', 'Administrador'];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-zone-chief';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively provision the Jefe de Zona account with a freshly chosen password.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Provisioning the Jefe de Zona account.');

        $data = $this->promptForUserData();

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $validated = $validator->validated();

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'email_verified_at' => now(),
        ]);

        foreach (self::ROLE_NAMES as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $user->assignRole(self::ROLE_NAMES);

        activity()
            ->performedOn($user)
            ->event('zone_chief_provisioned')
            ->withProperties(['channel' => 'console'])
            ->log('Zone Chief account provisioned via app:create-zone-chief.');

        $this->components->info("Zone Chief account created for {$user->email}.");

        return self::SUCCESS;
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function promptForUserData(): array
    {
        return [
            'name' => (string) $this->ask('Full name'),
            'email' => (string) $this->ask('Email address'),
            'password' => (string) $this->secret('Password'),
            'password_confirmation' => (string) $this->secret('Confirm password'),
        ];
    }
}
