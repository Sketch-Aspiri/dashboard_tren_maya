<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Authorization coverage for the dashboard's `view-dashboard` Gate
 * (App\Providers\AppServiceProvider), added per the code-reviewer /
 * security-auditor punch list: every authenticated route needs an
 * explicit role-backed authorization check, not just "logged in".
 */
class DashboardTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_zone_chief_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Jefe de Zona');
        $this->actingAsTwoFactorVerified($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_administrador_can_view_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Administrador');
        $this->actingAsTwoFactorVerified($user);

        $response = $this->get(route('dashboard'));

        $response->assertOk();
    }

    public function test_user_without_a_role_cannot_view_the_dashboard(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->get(route('dashboard'));

        $response->assertForbidden();
    }

    /**
     * Etapa 3 — Control de Asistencia Diaria: the "Estaciones capturadas
     * hoy" card is the first real (non-dummy) KPI, per the approved plan.
     */
    public function test_zone_chief_sees_the_estaciones_capturadas_hoy_kpi_with_the_right_count(): void
    {
        $estacionCompleta = Estacion::factory()->create(['is_operativa' => true]);
        $estacionPendiente = Estacion::factory()->create(['is_operativa' => true]);

        $empleadoCompleto = Empleado::factory()->create([
            'estacion_id' => $estacionCompleta->id,
            'estatus' => EmpleadoEstatus::Activo->value,
        ]);
        Empleado::factory()->create([
            'estacion_id' => $estacionPendiente->id,
            'estatus' => EmpleadoEstatus::Activo->value,
        ]);

        RegistroDiario::factory()->create([
            'empleado_id' => $empleadoCompleto->id,
            'estacion_id' => $estacionCompleta->id,
            'fecha' => CarbonImmutable::today(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        $this->actingAsTwoFactorVerified(tap(User::factory()->create())->assignRole('Jefe de Zona'));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Estaciones capturadas hoy');
        $response->assertSee('1/2');
    }

    public function test_dashboard_does_not_show_the_placeholder_dummy_kpis(): void
    {
        $this->actingAsTwoFactorVerified(tap(User::factory()->create())->assignRole('Jefe de Zona'));

        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Registros totales (dummy)');
        $response->assertDontSee('Actividad reciente (dummy)');
        $response->assertDontSee('Los indicadores mostrados son de ejemplo');
    }

    /**
     * Estación-role accounts have no Dashboard link in the nav at all
     * (routes/web.php + navigation.blade.php), but the underlying
     * `view-dashboard` Gate itself must still deny them if they somehow
     * reach the route directly — unrelated to Etapa 3, but a quick sanity
     * check that adding the new KPI/gate didn't loosen that.
     */
    public function test_estacion_user_still_cannot_view_the_dashboard(): void
    {
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        $user = User::factory()->create(['estacion_id' => $estacion->id]);
        $user->assignRole('Estación');
        $this->actingAsTwoFactorVerified($user);

        $response = $this->get(route('dashboard'));

        $response->assertForbidden();
    }
}
