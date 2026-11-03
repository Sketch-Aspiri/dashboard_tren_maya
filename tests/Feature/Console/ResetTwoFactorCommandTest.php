<?php

namespace Tests\Feature\Console;

use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Recuperación del 2FA por consola y obligación de registrarlo para todos los
 * roles (Jefe/Administrador, Edificio y Estación) cuando el 2FA está activo.
 */
class ResetTwoFactorCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function cuentaConRol(string $rol, bool $conDosFactores = true): User
    {
        $estacion = $rol === 'Estación' ? Estacion::factory()->create(['is_operativa' => true]) : null;
        $user = User::factory()->create([
            'estacion_id' => $estacion?->id,
            'google2fa_secret' => $conDosFactores ? 'TESTSECRETKEYFORSPECS123456' : null,
            'google2fa_enabled_at' => $conDosFactores ? now() : null,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_it_clears_the_secret_and_logs_the_reset(): void
    {
        $user = $this->cuentaConRol('Administrador');

        $this->artisan('app:reset-two-factor', ['email' => $user->email])->assertExitCode(0);

        $user->refresh();
        $this->assertNull($user->google2fa_secret);
        $this->assertNull($user->google2fa_enabled_at);
        $this->assertTrue($user->hasRole('Administrador'), 'el reseteo no toca los roles');

        $evento = Activity::query()->where('event', 'two_factor_reset')->sole();
        $this->assertSame($user->id, $evento->subject_id);
        $this->assertSame('console', $evento->properties['channel']);
    }

    public function test_it_fails_for_an_unknown_email(): void
    {
        $this->artisan('app:reset-two-factor', ['email' => 'nadie@example.com'])->assertExitCode(1);

        $this->assertSame(0, Activity::query()->where('event', 'two_factor_reset')->count());
    }

    public function test_a_reset_account_is_sent_to_enrollment_on_its_next_request(): void
    {
        $user = $this->cuentaConRol('Administrador');
        $this->artisan('app:reset-two-factor', ['email' => $user->email]);

        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
    }

    public function test_every_role_must_enroll_two_factor_when_it_is_enabled(): void
    {
        foreach (['Jefe de Zona', 'Administrador', 'Edificio', 'Estación'] as $rol) {
            $user = $this->cuentaConRol($rol, conDosFactores: false);

            $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('two-factor.setup'));
        }
    }
}
