<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Accesibilidad: las preferencias se aplican antes de pintar y el panel está con y sin sesión.
 */
class AccesibilidadTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    public function test_la_pantalla_de_acceso_ofrece_el_panel_y_aplica_la_preferencia_al_cargar(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('js/accesibilidad-inicial.js', false)
            ->assertSee('x-data="panelAccesibilidad"', false);
    }

    public function test_cada_pantalla_tiene_su_titulo_un_solo_h1_y_enlace_de_salto(): void
    {
        $this->actingAsTwoFactorVerified();

        foreach ([route('profile.edit'), route('notificaciones.index')] as $url) {
            $html = (string) $this->get($url)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('~<title>[^<]+ · [^<]+</title>~u', $html, $url);
            $this->assertSame(1, preg_match_all('~<h1\b~', $html), $url);
            $this->assertStringContainsString('href="#contenido"', $html, $url);
        }
    }

    public function test_solo_con_sesion_se_puede_mantener_viva(): void
    {
        $this->get(route('sesion.viva'))->assertRedirect(route('login'));

        $this->actingAsTwoFactorVerified();
        $this->get(route('sesion.viva'))->assertNoContent();
    }

    public function test_las_pantallas_con_sesion_ofrecen_el_panel(): void
    {
        $this->actingAsTwoFactorVerified();

        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('js/accesibilidad-inicial.js', false)
            ->assertSee('x-data="panelAccesibilidad"', false);
    }
}
