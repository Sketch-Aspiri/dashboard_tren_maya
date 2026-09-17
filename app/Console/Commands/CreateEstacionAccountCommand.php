<?php

namespace App\Console\Commands;

use App\Models\Estacion;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Interactively provisions one shared station account (role "Estación").
 * Run once per operational station (9 times) — mirrors
 * App\Console\Commands\CreateZoneChiefCommand's pattern: never a
 * known/default password, no mass-assignment from request input (see
 * CLAUDE.md and .claude/rules/server-conventions.md).
 */
class CreateEstacionAccountCommand extends Command
{
    private const ROLE_NAME = 'Estación';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:create-estacion-account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactively provision a shared station account (role "Estación") with a freshly chosen password.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->components->info('Provisioning a station ("Estación") account.');

        $estacion = $this->promptForEstacion();

        if ($estacion === null) {
            $this->components->error('No hay estaciones operativas registradas. Corre primero el seeder (php artisan db:seed --class=EstacionSeeder).');

            return self::FAILURE;
        }

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

        // estacion_id is deliberately not in User::$fillable (see
        // App\Models\User) — set explicitly here, the one place a
        // station account is ever provisioned.
        $user->forceFill(['estacion_id' => $estacion->id])->save();

        Role::findOrCreate(self::ROLE_NAME, 'web');
        $user->assignRole(self::ROLE_NAME);

        activity()
            ->performedOn($user)
            ->event('estacion_account_provisioned')
            ->withProperties(['channel' => 'console', 'estacion_id' => $estacion->id])
            ->log('Estación account provisioned via app:create-estacion-account.');

        $this->components->info("Estación account created for {$user->email} ({$estacion->nombre}).");

        return self::SUCCESS;
    }

    private function promptForEstacion(): ?Estacion
    {
        $estaciones = Estacion::query()
            ->where('is_operativa', true)
            ->orderBy('orden')
            ->get();

        if ($estaciones->isEmpty()) {
            return null;
        }

        $nombre = $this->choice('Estación', $estaciones->pluck('nombre')->all());

        return $estaciones->firstWhere('nombre', $nombre);
    }

    /**
     * @return array{name: string, email: string, password: string, password_confirmation: string}
     */
    private function promptForUserData(): array
    {
        return [
            'name' => (string) $this->ask('Nombre'),
            'email' => (string) $this->ask('Correo electrónico'),
            'password' => (string) $this->secret('Contraseña'),
            'password_confirmation' => (string) $this->secret('Confirma la contraseña'),
        ];
    }
}
