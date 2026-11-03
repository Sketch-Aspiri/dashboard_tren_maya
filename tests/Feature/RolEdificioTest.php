<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Rol "Edificio": empleados de la oficina zonal. Ven y editan todos los datos
 * y la estructura; no gestionan usuarios ni usan el asistente de Claude.
 */
class RolEdificioTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $user = User::factory()->create(['estacion_id' => $estacion?->id]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_role_seeder_creates_edificio_and_keeps_existing_assignments(): void
    {
        $jefe = $this->usuario('Administrador');

        $this->seed(RoleSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'Edificio']);
        $this->assertTrue($jefe->fresh()->hasRole('Administrador'));
        $this->assertSame(1, Role::where('name', 'Edificio')->count());
    }

    public function test_edificio_can_open_dashboard_and_manage_menu(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('secciones.administrar'))->assertOk();
    }

    public function test_edificio_can_manage_empleados(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('agenda.personal.index'))->assertOk();
        $this->get(route('agenda.personal.create'))->assertOk();
    }

    public function test_edificio_can_open_capture_for_any_station_and_past_date(): void
    {
        $edificioZonal = Estacion::factory()->create(['is_operativa' => false]);
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('asistencia.captura.index', [
            'estacion' => $edificioZonal->id,
            'fecha' => CarbonImmutable::yesterday()->toDateString(),
        ]))->assertOk()->assertSee(CarbonImmutable::yesterday()->toDateString());
    }

    public function test_edificio_can_see_asistencia_zona(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('asistencia.zona.index'))->assertOk();
    }

    public function test_edificio_cannot_manage_users(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->get(route('usuarios.index'))->assertForbidden();
        $this->get(route('usuarios.create'))->assertForbidden();
    }

    public function test_edificio_cannot_use_the_assistant(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->postJson(route('dashboard.asistente'), ['pregunta' => 'hola'])->assertForbidden();
    }

    public function test_menu_shows_personal_and_asistencia_to_zone_roles(): void
    {
        foreach (['Edificio', 'Jefe de Zona', 'Administrador'] as $rol) {
            $this->actingAsTwoFactorVerified($this->usuario($rol));

            $this->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Asistencia Zona Oriente')
                ->assertSee('Captura de asistencia');
        }
    }

    public function test_estacion_menu_shows_only_captura_de_asistencia(): void
    {
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $estacion));

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('Captura de asistencia')
            ->assertDontSee('Asistencia Zona Oriente');
    }
}
