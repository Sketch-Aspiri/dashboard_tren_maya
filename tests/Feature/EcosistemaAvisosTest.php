<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoTickets;
use App\Models\Cotizacion;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\CotizacionService;
use App\Services\Ecosistema\FirmaPuente;
use App\Services\Ecosistema\PuenteCliente;
use App\Services\SolicitudInsumoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Avisos al celular por la PWA de Tickets (fase 5 del ecosistema): quién recibe cada evento (solo cuentas
 * vinculadas), textos cortos y sin datos sensibles, cola con reintentos e idempotencia, POST firmado con el
 * cuerpo, recordatorio de asistencia una vez por estación y día, y el enlace del perfil.
 */
class EcosistemaAvisosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const HACIA_TICKETS = 'secreto-dashboard-a-tickets-de-prueba-0123456789';

    private const AVISOS = 'https://tickets.ejemplo.test/interno/avisos';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => self::HACIA_TICKETS,
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
    }

    private function usuario(string $rol, string $correo, array $atributos = [], bool $vinculado = true): User
    {
        $user = User::factory()->create(array_merge(['email' => $correo, 'sso_vinculado_at' => $vinculado ? now() : null], $atributos));
        $user->assignRole($rol);

        return $user;
    }

    private function solicitud(Estacion $estacion, string $estado = SolicitudInsumo::BORRADOR, int $mes = 12): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => $mes, 'estado' => $estado]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'cantidad' => 1, 'precio_unitario' => 10]);

        return $solicitud;
    }

    // --- Quién recibe cada evento ------------------------------------------------------------------------------------------------

    public function test_sending_a_solicitud_notifies_only_linked_zone_chiefs(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->usuario('Jefe de Zona', 'jefe@ejemplo.test');
        $this->usuario('Jefe de Zona', 'sinvincular@ejemplo.test', vinculado: false);
        $this->usuario('Estación', 'tulum@ejemplo.test', ['estacion_id' => $estacion->id]);
        $solicitud = $this->solicitud($estacion);

        app(SolicitudInsumoService::class)->enviar($solicitud, $this->usuario('Estación', 'otro@ejemplo.test', ['estacion_id' => $estacion->id]));

        Queue::assertPushed(EnviarAvisoTickets::class, 1);
        Queue::assertPushed(EnviarAvisoTickets::class, function (EnviarAvisoTickets $job) use ($solicitud): bool {
            $this->assertSame(['jefe@ejemplo.test'], $job->correos);
            $this->assertSame('Solicitud de insumos enviada', $job->titulo);
            $this->assertSame('Tulum envió su solicitud de diciembre 2026.', $job->cuerpo);
            $this->assertSame('https://dashboard.ejemplo.test/insumos/'.$solicitud->id, $job->url);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $job->ref);

            return true;
        });
    }

    public function test_returning_or_approving_notifies_only_linked_people_of_that_station(): void
    {
        Queue::fake();
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum']);
        $otra = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $this->usuario('Estación', 'tulum@ejemplo.test', ['estacion_id' => $tulum->id]);
        $this->usuario('Estación', 'tulum2@ejemplo.test', ['estacion_id' => $tulum->id], vinculado: false);
        $this->usuario('Estación', 'bacalar@ejemplo.test', ['estacion_id' => $otra->id]);
        $jefe = $this->usuario('Jefe de Zona', 'jefe@ejemplo.test');
        $servicio = app(SolicitudInsumoService::class);

        $servicio->revisar($this->solicitud($tulum, SolicitudInsumo::ENVIADA), ['accion' => 'devolver', 'comentario_zona' => 'Falta justificar'], $jefe);
        $servicio->revisar($this->solicitud($tulum, SolicitudInsumo::ENVIADA, 11), ['accion' => 'aprobar'], $jefe);
        // Guardar sin decidir no avisa.
        $servicio->revisar($this->solicitud($tulum, SolicitudInsumo::ENVIADA, 10), ['accion' => 'guardar'], $jefe);

        Queue::assertPushed(EnviarAvisoTickets::class, 2);
        $trabajos = Queue::pushed(EnviarAvisoTickets::class)->values();
        $this->assertSame(['tulum@ejemplo.test'], $trabajos[0]->correos);
        $this->assertSame('Solicitud de insumos observada', $trabajos[0]->titulo);
        $this->assertSame('Tu solicitud de diciembre 2026 tiene observaciones de Zona.', $trabajos[0]->cuerpo);
        $this->assertSame('https://dashboard.ejemplo.test/insumos/estaciones/'.$tulum->id, $trabajos[0]->url);
        $this->assertSame('Solicitud de insumos aprobada', $trabajos[1]->titulo);
        $this->assertSame('Tu solicitud de noviembre 2026 fue aprobada.', $trabajos[1]->cuerpo);
        // El comentario de Zona (dato libre) nunca viaja en el aviso.
        $this->assertStringNotContainsString('Falta justificar', $trabajos[0]->cuerpo);
    }

    public function test_a_quotation_that_turns_to_review_notifies_the_other_zone_chiefs_once(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['codigo' => '23']);
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::APROBADA]);
        $partida = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'numero' => 1, 'descripcion' => 'Cable', 'cantidad' => 2, 'precio_unitario' => 100, 'proveedor' => 'Truper']);
        $capturo = $this->usuario('Jefe de Zona', 'jefe1@ejemplo.test');
        $this->usuario('Jefe de Zona', 'jefe2@ejemplo.test');
        $servicio = app(CotizacionService::class);
        $datos = fn (array $extra = []): array => $extra + [
            'anio' => 2026, 'mes' => 12, 'proveedor' => 'Truper', 'folio' => 'COT-196', 'fecha' => '2026-11-10',
            'renglones' => [['partida_id' => $partida->id, 'descripcion' => 'Cable', 'cantidad' => 2, 'precio_unitario' => 100, 'coincidencia' => 'mismo']],
        ];

        // Correcta: no avisa.
        $cotizacion = $servicio->guardar(null, $datos(), null, $capturo);
        $this->assertSame(Cotizacion::OK, $cotizacion->validacion);
        Queue::assertNothingPushed();

        // Pasa a REVISAR (subtotal que no cuadra): avisa a los demás jefes.
        $cotizacion = $servicio->guardar($cotizacion, $datos(['subtotal' => 999]), null, $capturo);
        $this->assertSame(Cotizacion::REVISAR, $cotizacion->validacion);
        Queue::assertPushed(EnviarAvisoTickets::class, 1);
        $job = Queue::pushed(EnviarAvisoTickets::class)->first();
        $this->assertSame(['jefe2@ejemplo.test'], $job->correos);
        $this->assertSame('La cotización COT-196 tiene diferencias por revisar.', $job->cuerpo);
        $this->assertSame('https://dashboard.ejemplo.test/insumos/cotizaciones/'.$cotizacion->id, $job->url);

        // Sigue en REVISAR al guardar otra vez: no se repite el aviso.
        $servicio->guardar($cotizacion, $datos(['subtotal' => 888]), null, $capturo);
        Queue::assertPushed(EnviarAvisoTickets::class, 1);
    }

    public function test_texts_are_short_plain_and_without_control_characters(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['nombre' => "Estación\n".str_repeat('muy larga ', 20)]);
        $this->usuario('Jefe de Zona', 'jefe@ejemplo.test');

        app(SolicitudInsumoService::class)->enviar($this->solicitud($estacion), $this->usuario('Estación', 'e@ejemplo.test', ['estacion_id' => $estacion->id]));

        $job = Queue::pushed(EnviarAvisoTickets::class)->first();
        $this->assertLessThanOrEqual(60, mb_strlen($job->titulo));
        $this->assertLessThanOrEqual(120, mb_strlen($job->cuerpo));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $job->cuerpo);
    }

    public function test_without_the_bridge_nothing_is_queued_and_the_action_still_works(): void
    {
        Queue::fake();
        config(['ecosistema.puente.secreto_salida' => null]);
        $estacion = Estacion::factory()->create();
        $this->usuario('Jefe de Zona', 'jefe@ejemplo.test');
        $solicitud = $this->solicitud($estacion);

        app(SolicitudInsumoService::class)->enviar($solicitud, $this->usuario('Estación', 'e@ejemplo.test', ['estacion_id' => $estacion->id]));

        $this->assertSame(SolicitudInsumo::ENVIADA, $solicitud->fresh()->estado);
        Queue::assertNothingPushed();
    }

    public function test_a_failure_while_queueing_never_breaks_the_action(): void
    {
        $estacion = Estacion::factory()->create();
        $this->usuario('Jefe de Zona', 'jefe@ejemplo.test');
        $solicitud = $this->solicitud($estacion);
        // Una cola que falla al encolar (p. ej. la base de la cola no responde).
        config(['queue.default' => 'inexistente']);

        app(SolicitudInsumoService::class)->enviar($solicitud, $this->usuario('Estación', 'e@ejemplo.test', ['estacion_id' => $estacion->id]));

        $this->assertSame(SolicitudInsumo::ENVIADA, $solicitud->fresh()->estado);
    }

    // --- Trabajo y POST firmado --------------------------------------------------------------------------------------------------

    private function trabajo(): EnviarAvisoTickets
    {
        return new EnviarAvisoTickets(['jefe@ejemplo.test'], 'Solicitud de insumos enviada', 'Tulum envió su solicitud de diciembre 2026.', 'https://dashboard.ejemplo.test/insumos/5', 'ref-123');
    }

    public function test_the_job_posts_a_signed_json_body_to_tickets(): void
    {
        Http::fake([self::AVISOS => Http::response(['recipients' => 1])]);

        $this->trabajo()->handle(app(PuenteCliente::class));

        Http::assertSent(function ($request): bool {
            $cuerpo = $request->body();
            $encabezados = fn (string $nombre): string => (string) ($request->header($nombre)[0] ?? '');
            $canonica = implode("\n", [
                'POST', '/interno/avisos', '', $encabezados('X-Puente-Sistema'), $encabezados('X-Puente-Fecha'), $encabezados('X-Puente-Nonce'), hash('sha256', $cuerpo),
            ]);

            $this->assertSame('dashboard', $encabezados('X-Puente-Sistema'));
            $this->assertSame(hash_hmac('sha256', $canonica, self::HACIA_TICKETS), $encabezados('X-Puente-Firma'));
            $this->assertSame([
                'correos' => ['jefe@ejemplo.test'], 'titulo' => 'Solicitud de insumos enviada', 'cuerpo' => 'Tulum envió su solicitud de diciembre 2026.',
                'url' => 'https://dashboard.ejemplo.test/insumos/5', 'ref' => 'ref-123',
            ], json_decode($cuerpo, true));

            return $request->method() === 'POST' && str_starts_with((string) $request->header('Content-Type')[0], 'application/json');
        });
    }

    public function test_the_signature_matches_what_tickets_verifies(): void
    {
        // Mismo formato que BridgeSignature de Tickets: se verifica con la clase de aquí en modo "entrada".
        config(['ecosistema.puente.secreto_entrada' => self::HACIA_TICKETS]);
        Http::fake(function ($request) {
            $peticion = Request::create('/interno/avisos', 'POST', [], [], [], [], $request->body());
            foreach (['X-Puente-Sistema', 'X-Puente-Fecha', 'X-Puente-Nonce', 'X-Puente-Firma'] as $nombre) {
                $peticion->headers->set($nombre, $request->header($nombre)[0]);
            }
            // Lo firma "el Dashboard"; quien verifica es otro sistema, así que se simula su nombre.
            config(['ecosistema.sistema' => 'tickets']);
            $this->assertSame('dashboard', app(FirmaPuente::class)->verificar($peticion));
            config(['ecosistema.sistema' => 'dashboard']);

            return Http::response(['recipients' => 1]);
        });

        $this->trabajo()->handle(app(PuenteCliente::class));
    }

    public function test_the_job_fails_so_it_is_retried_when_tickets_does_not_receive_it(): void
    {
        foreach ([Http::response('', 500), Http::response('', 302, ['Location' => 'https://malo.test/x']), Http::response(['error' => 'invalid'], 422)] as $respuesta) {
            Http::fake([self::AVISOS => $respuesta, 'https://malo.test/*' => Http::response(['recipients' => 1])]);

            try {
                $this->trabajo()->handle(app(PuenteCliente::class));
                $this->fail('Debió lanzar una excepción para reintentar.');
            } catch (\RuntimeException $e) {
                $this->assertStringNotContainsString(self::HACIA_TICKETS, $e->getMessage());
            }

            Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://malo.test'));
        }

        $this->assertSame(4, $this->trabajo()->tries);
        $this->assertSame([30, 120, 600], $this->trabajo()->backoff());
    }

    public function test_the_job_does_nothing_without_the_bridge(): void
    {
        Http::fake();
        config(['ecosistema.puente.secreto_salida' => null]);

        $this->trabajo()->handle(app(PuenteCliente::class));

        Http::assertNothingSent();
    }

    // --- Recordatorio de asistencia ----------------------------------------------------------------------------------------------

    public function test_the_attendance_reminder_goes_once_per_station_and_day_only_to_pending_ones(): void
    {
        Queue::fake();
        $pendiente = Estacion::factory()->create(['nombre' => 'Tulum', 'is_operativa' => true]);
        $sinPlantilla = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $pendiente->id]);
        $this->usuario('Estación', 'tulum@ejemplo.test', ['estacion_id' => $pendiente->id]);
        $this->usuario('Estación', 'tulum2@ejemplo.test', ['estacion_id' => $pendiente->id], vinculado: false);
        $this->usuario('Estación', 'bacalar@ejemplo.test', ['estacion_id' => $sinPlantilla->id]);

        $this->artisan('asistencia:recordar-pendientes')->assertSuccessful();
        $this->artisan('asistencia:recordar-pendientes')->assertSuccessful();

        Queue::assertPushed(EnviarAvisoTickets::class, 1);
        $job = Queue::pushed(EnviarAvisoTickets::class)->first();
        $this->assertSame(['tulum@ejemplo.test'], $job->correos);
        $this->assertSame('Asistencia pendiente', $job->titulo);
        $this->assertSame('Tulum: aún falta capturar la asistencia de hoy.', $job->cuerpo);
        $this->assertSame('https://dashboard.ejemplo.test/asistencia/captura', $job->url);

        // Al día siguiente vuelve a avisar.
        $this->travel(1)->day();
        $this->artisan('asistencia:recordar-pendientes')->assertSuccessful();
        Queue::assertPushed(EnviarAvisoTickets::class, 2);
    }

    public function test_the_reminder_is_a_noop_without_the_bridge_and_is_scheduled_on_weekdays(): void
    {
        Queue::fake();
        config(['ecosistema.puente.secreto_salida' => null]);
        Empleado::factory()->create(['estacion_id' => Estacion::factory()->create(['is_operativa' => true])->id]);

        $this->artisan('asistencia:recordar-pendientes')->assertSuccessful();
        Queue::assertNothingPushed();
        $this->assertFalse(Cache::has('ecosistema:recordatorio:asistencia:'.now('America/Cancun')->toDateString().':1'));

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'asistencia:recordar-pendientes'));
        $this->assertNotNull($evento);
        $this->assertSame('0 10 * * 1-5', $evento->expression);
        $this->assertSame('America/Cancun', (string) $evento->timezone);
    }

    // --- Perfil -----------------------------------------------------------------------------------------------------------------------

    public function test_the_profile_links_to_activate_notifications_in_tickets(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona', 'jefe@ejemplo.test'));

        $this->get('/profile')->assertOk()
            ->assertSee('Avisos en tu celular')
            ->assertSee('href="https://tickets.ejemplo.test/notifications"', false);

        config(['ecosistema.puente.secreto_salida' => null]);
        $this->get('/profile')->assertOk()->assertDontSee('Avisos en tu celular');
    }
}
