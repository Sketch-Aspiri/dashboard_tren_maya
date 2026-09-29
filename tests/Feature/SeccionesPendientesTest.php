<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the menu groups and placeholder pages taken from
 * "Dashboard Tren Maya.pptx" (config/navegacion.php): every item without a
 * real module yet opens a "pendiente de agregar información" page.
 */
class SeccionesPendientesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('secciones.show', ['cronograma-de-informes', 'cronograma-de-actividades']))
            ->assertRedirect(route('login'));
    }

    public function test_zone_chief_and_administrador_can_open_every_pending_section(): void
    {
        foreach (['Jefe de Zona', 'Administrador'] as $role) {
            $this->actingAsTwoFactorVerified($this->userWithRole($role));

            foreach (config('navegacion.grupos') as $grupo) {
                foreach ($grupo['pendientes'] ?? [] as $label) {
                    $this->get(route('secciones.show', [$grupo['id'], Str::slug($label)]))
                        ->assertOk()
                        ->assertSee($label)
                        ->assertSee('Pendiente de agregar información');
                }
            }
        }
    }

    public function test_estacion_cannot_open_a_pending_section(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('secciones.show', ['cronograma-de-informes', 'cronograma-de-actividades']))
            ->assertForbidden();
    }

    public function test_unknown_section_returns_not_found(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('secciones.show', ['cronograma-de-informes', 'no-existe']))->assertNotFound();
        $this->get(route('secciones.show', ['no-existe', 'cronograma-de-actividades']))->assertNotFound();
    }

    public function test_zone_chief_menu_lists_every_group_from_the_ppt(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $response = $this->get(route('dashboard'));

        foreach (['Cronograma de informes', 'RR.HH.', 'Recursos materiales', 'Recursos financieros', 'SGD. Fis', 'Otros'] as $grupo) {
            $response->assertSee($grupo);
        }
        $response->assertSee('Mapa del Tren Maya');
    }

    public function test_estacion_menu_hides_zone_only_groups(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $response = $this->get(route('asistencia.captura.index'));

        $response->assertOk()
            ->assertDontSee('Cronograma de informes')
            ->assertDontSee('SGD. Fis')
            ->assertSee('Recursos materiales');
    }
}
