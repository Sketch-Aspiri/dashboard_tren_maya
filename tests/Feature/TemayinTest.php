<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use App\Support\Temayin;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Temayin (preguntas frecuentes, igual que en Tickets): aparece en las
 * páginas autenticadas, no en el login, y solo ofrece preguntas y enlaces de
 * secciones que el rol puede abrir.
 */
class TemayinTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function usuario(string $rol, array $datos = []): User
    {
        return tap(User::factory()->create($datos))->assignRole($rol);
    }

    public function test_zone_chief_sees_temayin_on_authenticated_pages_with_zone_links(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('x-data="temayin"', false)
            ->assertSee(asset('temayin-soporte.jpg'), false)
            ->assertSee('¿Cómo genero la comprobación en PDF?')
            ->assertSee('¿Cómo sé si una factura está vigente en el SAT?')
            ->assertSee(config('services.tickets.soporte_url'), false);

        $this->get(route('insumos.facturas.index'))->assertOk()->assertSee('x-data="temayin"', false);
    }

    public function test_login_page_does_not_render_temayin(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('x-data="temayin"', false);
    }

    public function test_station_only_gets_questions_and_links_it_can_open(): void
    {
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        $usuario = $this->usuario('Estación', ['estacion_id' => $estacion->id]);

        $preguntas = app(Temayin::class)->preguntas($usuario);
        $urls = array_filter(array_column($preguntas, 'url'));

        $this->assertArrayHasKey('solicitud_estacion', $preguntas);
        $this->assertArrayNotHasKey('facturas', $preguntas);
        $this->assertArrayNotHasKey('comprobacion', $preguntas);
        $this->assertArrayNotHasKey('proveedores', $preguntas);
        $this->assertArrayNotHasKey('personal', $preguntas);
        $this->assertNotContains(route('insumos.facturas.index'), $urls);
        $this->assertNotContains(route('agenda.personal.index'), $urls);

        $this->actingAsTwoFactorVerified($usuario);
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('x-data="temayin"', false)
            ->assertSee('¿Cómo pido insumos para mi estación?')
            ->assertDontSee('¿Cómo cargo una factura?');
    }

    public function test_every_offered_link_is_a_page_the_user_can_open(): void
    {
        // La captura de asistencia necesita al menos una estación operativa.
        Estacion::factory()->create(['is_operativa' => true]);

        foreach (['Jefe de Zona', 'Administrador', 'Edificio'] as $rol) {
            $usuario = $this->usuario($rol);
            $this->actingAsTwoFactorVerified($usuario);

            foreach (app(Temayin::class)->preguntas($usuario) as $clave => $faq) {
                // Los enlaces a Tickets (otro sistema del ecosistema) se prueban en EcosistemaTest.
                if ($faq['url'] !== null && str_starts_with($faq['url'], config('app.url'))) {
                    $this->assertSame(200, $this->get($faq['url'])->status(), "{$rol}: {$clave} ({$faq['url']})");
                    $this->assertNotEmpty($faq['enlace'], "{$rol}: {$clave}");
                }
            }
        }
    }

    public function test_answers_travel_as_escaped_json_and_never_as_markup(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $html = $this->get(route('dashboard'))->assertOk()->getContent();

        preg_match('/data-faqs="([^"]+)"/', $html, $coincidencia);
        $faqs = json_decode(html_entity_decode($coincidencia[1] ?? '', ENT_QUOTES), true);

        $this->assertIsArray($faqs);
        $this->assertSame(route('insumos.facturas.index'), $faqs['facturas']['url']);
        $this->assertStringNotContainsString('x-html', substr($html, (int) strpos($html, 'x-data="temayin"'), 6000));
    }
}
