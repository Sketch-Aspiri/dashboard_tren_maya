<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Ecosistema Zona Oriente (fase 1): selector Dashboard | Tickets, pie común,
 * tema compartido por cookie y tipografía servida desde el propio sitio.
 */
class EcosistemaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config(['ecosistema.urls.tickets' => 'https://tickets.ejemplo.test']);
    }

    private function jefe(): User
    {
        return tap(User::factory()->create())->assignRole('Jefe de Zona');
    }

    public function test_las_paginas_ofrecen_el_selector_de_sistema_y_el_pie_comun(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $html = $this->get(route('dashboard'))->assertOk()
            ->assertSee('https://tickets.ejemplo.test/dashboard', false)
            ->assertSee('Sistema de Gestión Territorial Zona Oriente Tren Maya')
            ->getContent();

        $this->assertMatchesRegularExpression('#<a href="[^"]+/dashboard"\s+aria-current="page"#', $html);
        $this->assertSame(2, substr_count($html, 'aria-label="Sistemas de la Zona Oriente"'));
    }

    public function test_el_tema_se_lee_primero_de_la_cookie_compartida(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('tema=(sistema|claro|oscuro)', false);

        $fuente = (string) file_get_contents(resource_path('js/tema.js'));
        $this->assertStringContainsString('SameSite=Lax', $fuente);
        $this->assertStringContainsString('domain=.', $fuente);
    }

    public function test_la_tipografia_ya_no_viene_de_un_cdn(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->get(route('dashboard'))->assertOk()->assertDontSee('fonts.bunny.net');
        $this->get(route('login'))->assertDontSee('fonts.bunny.net');
    }

    public function test_temayin_ofrece_ir_a_tickets_y_a_mis_pendientes(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('¿Qué es Tickets y cómo llego?')
            ->assertSee('https:\/\/tickets.ejemplo.test\/tickets\/pending', false);
    }
}
