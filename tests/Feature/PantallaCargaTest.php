<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Pantalla de carga: los dos marcos (con y sin sesión) la incluyen. El comportamiento del navegador se prueba en
 * tests/js/pantalla-carga.test.js.
 */
class PantallaCargaTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    public function test_la_pantalla_de_acceso_incluye_la_pantalla_de_carga(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('id="app-page-loader"', false)
            ->assertSee('data-cerrar-carga', false)
            ->assertSee('logo.png', false);
    }

    public function test_las_pantallas_con_sesion_incluyen_la_pantalla_de_carga(): void
    {
        $this->actingAsTwoFactorVerified();

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('id="app-page-loader"', false);
    }
}
