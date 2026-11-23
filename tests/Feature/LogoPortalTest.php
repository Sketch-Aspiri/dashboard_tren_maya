<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** El logo de la barra abre el panel «Mis sistemas» del portal (/inicio), no su portada pública. */
class LogoPortalTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private function jefe(): User
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Jefe de Zona');

        return $user;
    }

    public function test_el_logo_lleva_al_panel_del_portal_y_no_a_su_portada(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('href="https://zonaorientetm.tech/inicio"', false)
            ->assertDontSee('href="https://zonaorientetm.tech"', false);
    }

    public function test_la_direccion_sale_de_la_configuracion_del_portal(): void
    {
        $this->assertSame('https://zonaorientetm.tech/inicio', config('ecosistema.portal_panel'));

        config(['ecosistema.portal_panel' => 'https://portal.ejemplo.test/inicio']);
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->get(route('dashboard'))->assertSee('href="https://portal.ejemplo.test/inicio"', false);
    }

    public function test_la_direccion_base_del_portal_no_cambia_para_otros_enlaces(): void
    {
        $this->assertSame('https://zonaorientetm.tech', config('ecosistema.portal_inicio'));
    }
}
