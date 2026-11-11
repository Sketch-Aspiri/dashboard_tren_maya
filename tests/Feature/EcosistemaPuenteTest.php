<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use App\Support\Temayin;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Puente interno con Tickets (fase 3 del ecosistema): firma HMAC por
 * dirección, ventana de tiempo, nonce de un solo uso e IPs; "Reportar una
 * falla" con contexto (entregado una vez y solo a la misma persona) y la
 * tarjeta "Mis pendientes en Tickets", que se degrada si Tickets no responde.
 */
class EcosistemaPuenteTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const DESDE_TICKETS = 'secreto-tickets-a-dashboard-de-prueba-0123456789';

    private const HACIA_TICKETS = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const PENDIENTES = 'https://tickets.ejemplo.test/interno/pendientes*';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.puente.secreto_entrada' => self::DESDE_TICKETS,
            'ecosistema.puente.secreto_salida' => self::HACIA_TICKETS,
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.puente.ips' => [],
        ]);
    }

    private function usuario(string $rol = 'Jefe de Zona', string $correo = 'jefe@ejemplo.test', array $atributos = []): User
    {
        $user = User::factory()->create(['email' => $correo] + $atributos);
        $user->assignRole($rol);

        return $user;
    }

    /**
     * Encabezados como los firmaría Tickets (mismo formato que BridgeSignature).
     *
     * @param  array<string, string>  $consulta
     * @return array<string, string>
     */
    private function firmado(string $ruta, array $consulta, ?int $fecha = null, ?string $nonce = null, string $secreto = self::DESDE_TICKETS, string $sistema = 'tickets'): array
    {
        $fecha ??= now()->getTimestamp();
        $nonce ??= Str::random(40);
        ksort($consulta);
        $canonica = implode("\n", ['GET', $ruta, http_build_query($consulta, '', '&', PHP_QUERY_RFC3986), $sistema, (string) $fecha, $nonce, hash('sha256', '')]);

        return [
            'X-Puente-Sistema' => $sistema,
            'X-Puente-Fecha' => (string) $fecha,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonica, $secreto),
            'Accept' => 'application/json',
        ];
    }

    private function pedirContexto(string $id, string $correo, ?array $encabezados = null): TestResponse
    {
        $ruta = '/interno/contextos/'.$id;
        $consulta = ['correo' => $correo];

        return $this->get($ruta.'?'.http_build_query($consulta), $encabezados ?? $this->firmado($ruta, $consulta));
    }

    /**
     * Reporta una falla desde una página y devuelve el id que viajó a Tickets.
     */
    private function reportar(User $user, string $desde = '/insumos'): string
    {
        $this->actingAsTwoFactorVerified($user);
        $destino = $this->get(route('ecosistema.reportar', ['desde' => $desde]))->assertRedirect()->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://tickets\.ejemplo\.test/tickets/create\?prefill=[A-Za-z0-9]{40}$#', $destino);

        return substr($destino, -40);
    }

    // --- Protección del puente ------------------------------------------------------------------------------------------------

    public function test_without_secret_the_bridge_does_not_exist(): void
    {
        config(['ecosistema.puente.secreto_entrada' => null]);

        $this->pedirContexto(Str::random(40), 'jefe@ejemplo.test')->assertNotFound();
    }

    public function test_wrong_secret_system_query_or_dates_are_rejected(): void
    {
        $id = $this->reportar($this->usuario());
        $ruta = '/interno/contextos/'.$id;
        $consulta = ['correo' => 'jefe@ejemplo.test'];

        $this->pedirContexto($id, 'jefe@ejemplo.test', $this->firmado($ruta, $consulta, secreto: self::HACIA_TICKETS))->assertUnauthorized();
        $this->pedirContexto($id, 'jefe@ejemplo.test', $this->firmado($ruta, $consulta, sistema: 'dashboard'))->assertUnauthorized();
        $this->pedirContexto($id, 'jefe@ejemplo.test', $this->firmado($ruta, ['correo' => 'otra@ejemplo.test']))->assertUnauthorized();
        $this->pedirContexto($id, 'jefe@ejemplo.test', $this->firmado($ruta, $consulta, fecha: now()->subMinutes(6)->getTimestamp()))->assertUnauthorized();
        $this->get($ruta.'?correo=jefe%40ejemplo.test', ['Accept' => 'application/json'])->assertUnauthorized();

        // Nada de lo anterior consumió el contexto.
        $this->pedirContexto($id, 'jefe@ejemplo.test')->assertOk();
    }

    public function test_a_signature_is_accepted_only_once_and_ips_are_enforced(): void
    {
        $id = $this->reportar($this->usuario());
        $encabezados = $this->firmado('/interno/contextos/'.$id, ['correo' => 'jefe@ejemplo.test']);

        config(['ecosistema.puente.ips' => ['10.0.0.5']]);
        $this->pedirContexto($id, 'jefe@ejemplo.test', $encabezados)->assertForbidden();

        config(['ecosistema.puente.ips' => ['127.0.0.1']]);
        $this->pedirContexto($id, 'jefe@ejemplo.test', $encabezados)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->pedirContexto($id, 'jefe@ejemplo.test', $encabezados)->assertUnauthorized();
    }

    // --- Reportar una falla ------------------------------------------------------------------------------------------------------

    public function test_report_sends_context_once_and_only_to_the_same_person(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        $jefe = $this->usuario(atributos: ['estacion_id' => $estacion->id]);
        $id = $this->reportar($jefe, '/insumos/facturas');

        $this->pedirContexto($id, 'otra@ejemplo.test')->assertNotFound();
        // El intento ajeno lo consumió: tampoco lo obtiene después nadie más.
        $this->pedirContexto($id, 'jefe@ejemplo.test')->assertNotFound();

        $id = $this->reportar($jefe, '/insumos/facturas');
        $contexto = $this->pedirContexto($id, 'JEFE@ejemplo.test')->assertOk()->json();

        $this->assertSame('Falla en el Dashboard: /insumos/facturas', $contexto['titulo']);
        $this->assertStringContainsString(config('app.url').'/insumos/facturas', $contexto['descripcion']);
        $this->assertStringContainsString('Rol: Jefe de Zona', $contexto['descripcion']);
        $this->assertStringContainsString('Estación: Tulum', $contexto['descripcion']);
        $this->assertStringNotContainsString('jefe@ejemplo.test', $contexto['descripcion'], 'no lleva datos personales');
        $this->pedirContexto($id, 'jefe@ejemplo.test')->assertNotFound();

        $this->assertTrue(Activity::query()->where('log_name', 'ecosistema')->where('event', 'reporte_falla')->where('causer_id', $jefe->id)->exists());
    }

    public function test_report_only_keeps_a_safe_relative_path(): void
    {
        $jefe = $this->usuario();

        foreach (['https://malo.test/x', '//malo.test', '/insumos?token=secreto', 'insumos', "/a\nb"] as $desde) {
            $id = $this->reportar($jefe, $desde);
            $this->assertSame('Falla en el Dashboard: /', $this->pedirContexto($id, 'jefe@ejemplo.test')->json('titulo'), $desde);
        }
    }

    public function test_report_requires_login_and_without_bridge_opens_the_plain_support_form(): void
    {
        $this->get(route('ecosistema.reportar'))->assertRedirect(route('login'));

        config(['ecosistema.puente.secreto_salida' => null, 'services.tickets.soporte_url' => 'https://tickets.ejemplo.test/tickets/create']);
        $this->actingAsTwoFactorVerified($this->usuario());

        $this->get(route('ecosistema.reportar', ['desde' => '/insumos']))->assertRedirect('https://tickets.ejemplo.test/tickets/create');
    }

    public function test_temayin_support_link_goes_through_the_report_route_with_the_current_page(): void
    {
        $jefe = $this->usuario();
        $this->actingAsTwoFactorVerified($jefe);
        $this->get('/profile');

        $this->assertSame(route('ecosistema.reportar', ['desde' => '/profile']), app(Temayin::class)->urlSoporte());
        $this->assertSame(app(Temayin::class)->urlSoporte(), app(Temayin::class)->preguntas($jefe)['soporte']['url']);
    }

    // --- Mis pendientes en Tickets ------------------------------------------------------------------------------------------------

    private function resumen(array $cambios = []): array
    {
        return $cambios + [
            'linked' => true,
            'counts' => ['tickets' => 3, 'activities' => 2, 'overdue' => 1, 'in_review' => 1],
            'urgent' => [
                ['type' => 'ticket', 'folio' => 'TM-2026-0007', 'title' => 'Impresora <script>alert(1)</script>', 'status' => 'Pendiente', 'priority' => 'Alta', 'due_date' => '2026-11-01', 'overdue' => true, 'url' => '/tickets/7'],
                ['type' => 'activity', 'folio' => 'ACT-2026-0012', 'title' => 'Reporte semanal', 'status' => 'En proceso', 'priority' => 'Media', 'due_date' => null, 'overdue' => false, 'url' => 'https://malo.test/robar'],
            ],
            'pending_url' => '/tickets/pending',
        ];
    }

    public function test_zone_chief_sees_their_tickets_pending_card_escaped_and_cached(): void
    {
        Http::fake([self::PENDIENTES => Http::response($this->resumen())]);
        $this->actingAsTwoFactorVerified($this->usuario());

        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Mis pendientes en Tickets')
            ->assertSee('TM-2026-0007')
            ->assertSee('Impresora &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('href="https://tickets.ejemplo.test/tickets/7"', false)
            // Una URL que no es una ruta relativa de Tickets se reemplaza por "Mis pendientes".
            ->assertDontSee('malo.test')
            ->assertSee('href="https://tickets.ejemplo.test/tickets/pending"', false);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $consulta);

            return $consulta === ['correo' => 'jefe@ejemplo.test']
                && $request->header('X-Puente-Sistema') === ['dashboard']
                && $request->hasHeader('X-Puente-Firma');
        });

        $this->get(route('dashboard'))->assertOk();
        Http::assertSentCount(1);
    }

    public function test_station_home_also_shows_the_card(): void
    {
        Http::fake([self::PENDIENTES => Http::response($this->resumen())]);
        $estacion = Estacion::factory()->create(['is_operativa' => true]);
        $this->actingAsTwoFactorVerified($this->usuario('Estación', 'tulum@ejemplo.test', ['estacion_id' => $estacion->id]));

        $this->get(route('dashboard'))->assertOk()->assertSee('Secciones para tu estación')->assertSee('Mis pendientes en Tickets')->assertSee('Reporte semanal');
    }

    public function test_card_degrades_when_tickets_is_down_or_the_account_is_not_linked(): void
    {
        $ticketsArriba = false;
        Http::fake([self::PENDIENTES => function () use (&$ticketsArriba) {
            return $ticketsArriba ? Http::response(['linked' => false]) : Http::response('', 500);
        }]);
        $this->actingAsTwoFactorVerified($this->usuario());

        $this->get(route('dashboard'))->assertOk()->assertSee('Tickets no está disponible por el momento');

        // La falla también se guarda 60 s (no frena cada carga); al vencer se vuelve a preguntar.
        $ticketsArriba = true;
        $this->get(route('dashboard'))->assertOk()->assertSee('Tickets no está disponible por el momento');
        Cache::flush();

        $this->get(route('dashboard'))->assertOk()->assertSee('entra una vez a Tickets');
    }

    public function test_without_bridge_there_is_no_card_and_no_call(): void
    {
        config(['ecosistema.puente.secreto_salida' => null]);
        Http::fake();
        $this->actingAsTwoFactorVerified($this->usuario());

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Mis pendientes en Tickets');
        Http::assertNothingSent();
    }
}
