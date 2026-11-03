<?php

namespace Tests\Feature\Console;

use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\EstacionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Covers the interactive provisioning command for the 9 shared station
 * accounts (role "Estación"). Mirrors CreateZoneChiefCommandTest.
 */
class CreateEstacionAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(EstacionSeeder::class);
    }

    public function test_it_provisions_a_station_account_with_prompted_credentials(): void
    {
        $this->artisan('app:create-estacion-account')
            ->expectsQuestion('Estación', 'Bacalar')
            ->expectsQuestion('Nombre', 'Estación Bacalar')
            ->expectsQuestion('Correo electrónico', 'bacalar@example.com')
            ->expectsQuestion('Contraseña', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirma la contraseña', 'Correct-horse-battery-staple-1')
            ->assertExitCode(0);

        $user = User::where('email', 'bacalar@example.com')->first();
        $bacalar = Estacion::where('nombre', 'Bacalar')->firstOrFail();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('Estación'));
        $this->assertSame($bacalar->id, $user->estacion_id);
        $this->assertTrue(Hash::check('Correct-horse-battery-staple-1', $user->password));
    }

    public function test_it_can_create_an_account_for_the_edificio_zonal(): void
    {
        // "Edificio Zonal Este" (is_operativa = false) can also have an
        // Estación account, so it can receive section/sheet permissions.
        $this->artisan('app:create-estacion-account')
            ->expectsQuestion('Estación', 'Edificio Zonal Este')
            ->expectsQuestion('Nombre', 'Edificio Zonal')
            ->expectsQuestion('Correo electrónico', 'edificio@example.com')
            ->expectsQuestion('Contraseña', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirma la contraseña', 'Correct-horse-battery-staple-1')
            ->assertExitCode(0);

        $edificio = Estacion::where('nombre', 'Edificio Zonal Este')->firstOrFail();
        $this->assertSame($edificio->id, User::where('email', 'edificio@example.com')->value('estacion_id'));
    }

    public function test_it_fails_when_password_confirmation_does_not_match(): void
    {
        $this->artisan('app:create-estacion-account')
            ->expectsQuestion('Estación', 'Bacalar')
            ->expectsQuestion('Nombre', 'Estación Bacalar')
            ->expectsQuestion('Correo electrónico', 'bacalar@example.com')
            ->expectsQuestion('Contraseña', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirma la contraseña', 'a-different-password')
            ->assertExitCode(1);

        $this->assertDatabaseMissing('users', ['email' => 'bacalar@example.com']);
    }

    public function test_it_fails_when_email_is_already_taken(): void
    {
        User::factory()->create(['email' => 'bacalar@example.com']);

        $this->artisan('app:create-estacion-account')
            ->expectsQuestion('Estación', 'Bacalar')
            ->expectsQuestion('Nombre', 'Estación Bacalar')
            ->expectsQuestion('Correo electrónico', 'bacalar@example.com')
            ->expectsQuestion('Contraseña', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirma la contraseña', 'Correct-horse-battery-staple-1')
            ->assertExitCode(1);

        $this->assertSame(1, User::where('email', 'bacalar@example.com')->count());
    }

    public function test_it_logs_the_provisioning_as_an_activity(): void
    {
        $this->artisan('app:create-estacion-account')
            ->expectsQuestion('Estación', 'Bacalar')
            ->expectsQuestion('Nombre', 'Estación Bacalar')
            ->expectsQuestion('Correo electrónico', 'bacalar@example.com')
            ->expectsQuestion('Contraseña', 'Correct-horse-battery-staple-1')
            ->expectsQuestion('Confirma la contraseña', 'Correct-horse-battery-staple-1')
            ->assertExitCode(0);

        $user = User::where('email', 'bacalar@example.com')->firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'estacion_account_provisioned')
            ->first();

        $this->assertNotNull($activity);
    }
}
