<?php

namespace Tests\Feature;

use App\Jobs\EnviarActividadTickets;
use App\Models\EcosistemaVinculo;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\Factura;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\Ecosistema\PuenteCliente;
use App\Services\Sat\ConsultorSat;
use App\Services\Sat\ResultadoSat;
use App\Services\SolicitudInsumoService;
use App\Services\VerificacionSatService;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Actividades que Tickets crea por eventos del Dashboard (fase 4 del ecosistema): qué eventos se mandan y con qué
 * datos mínimos, ref único para no duplicar, trabajo en cola que guarda el folio y el enlace validados, rechazos de
 * configuración sin reintentos, comando de asistencia una vez por estación y día, y «Seguimiento en Tickets».
 */
class EcosistemaActividadesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const ACTIVIDADES = 'https://tickets.ejemplo.test/interno/actividades';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.actividades.eventos' => ['insumos_observada', 'insumos_devuelta', 'factura_cancelada', 'asistencia_pendiente'],
        ]);
    }

    private function usuario(string $rol, array $atributos = []): User
    {
        $user = User::factory()->create($atributos);
        $user->assignRole($rol);

        return $user;
    }

    private function solicitud(Estacion $estacion, string $estado = SolicitudInsumo::ENVIADA, int $mes = 12): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => $mes, 'estado' => $estado]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'cantidad' => 1, 'precio_unitario' => 10]);

        return $solicitud;
    }

    private function factura(): Factura
    {
        Storage::disk('local')->put('facturas/xml/a.xml', '<cfdi/>');

        return Factura::query()->create([
            'anio' => 2026, 'mes' => 10, 'uuid' => '949E7F59-1302-46BF-A27C-D502299B7CA5', 'serie' => 'A', 'folio' => '1', 'fecha' => '2026-10-05',
            'emisor_rfc' => 'GFC051130D29', 'emisor_nombre' => 'Proveedor Reservado SA', 'receptor_rfc' => 'TMA220603D80', 'tipo_comprobante' => 'I', 'moneda' => 'MXN',
            'subtotal' => 100, 'iva' => 8, 'total' => 108, 'estado' => Factura::OK, 'xml_ruta' => 'facturas/xml/a.xml',
        ]);
    }

    private function trabajo(array $cambios = []): EnviarActividadTickets
    {
        $d = $cambios + [
            'evento' => 'insumos_observada', 'ref' => 'dashboard:solicitud:5:observada:1790000000', 'estacion' => '23',
            'titulo' => 'Solicitud de insumos observada: Tulum', 'descripcion' => 'Corrígela y vuelve a enviarla.',
            'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3', 'tipo' => SolicitudInsumo::class, 'id' => 5,
        ];

        return new EnviarActividadTickets($d['evento'], $d['ref'], $d['estacion'], $d['titulo'], $d['descripcion'], $d['url'], $d['tipo'], $d['id']);
    }

    // --- Eventos ----------------------------------------------------------------------------------------------------------------------

    public function test_a_solicitud_returned_by_zone_asks_for_an_activity_with_the_observations(): void
    {
        Queue::fake();
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '26']);
        $jefe = $this->usuario('Jefe de Zona');
        $servicio = app(SolicitudInsumoService::class);

        $solicitud = $servicio->revisar($this->solicitud($tulum), ['accion' => 'devolver', 'comentario_zona' => "Falta justificar\nlos focos"], $jefe);

        Queue::assertPushed(EnviarActividadTickets::class, 1);
        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $this->assertSame('insumos_observada', $job->evento);
        $this->assertSame('dashboard:solicitud:'.$solicitud->id.':observada:'.$solicitud->revisada_at->getTimestamp(), $job->ref);
        $this->assertSame('26', $job->estacion);
        $this->assertSame('Solicitud de insumos observada: Tulum', $job->titulo);
        $this->assertSame('La Zona devolvió la solicitud de diciembre 2026 de Tulum con observaciones. Observaciones: Falta justificar los focos Corrígela y vuelve a enviarla.', $job->descripcion);
        $this->assertSame('https://dashboard.ejemplo.test/insumos/estaciones/'.$tulum->id, $job->url);
        $this->assertSame([SolicitudInsumo::class, $solicitud->id], [$job->vinculableTipo, $job->vinculableId]);

        // Aprobar o solo guardar no abre actividad.
        $servicio->revisar($this->solicitud($tulum, mes: 11), ['accion' => 'aprobar'], $jefe);
        $servicio->revisar($this->solicitud($tulum, mes: 10), ['accion' => 'guardar'], $jefe);
        Queue::assertPushed(EnviarActividadTickets::class, 1);
    }

    public function test_texts_are_short_plain_and_without_amounts_or_personal_data(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['nombre' => "Estación\n".str_repeat('muy larga ', 20), 'codigo' => '26']);

        app(SolicitudInsumoService::class)->revisar($this->solicitud($estacion), ['accion' => 'devolver', 'comentario_zona' => str_repeat('x', 5000)], $this->usuario('Jefe de Zona'));

        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $this->assertLessThanOrEqual(120, mb_strlen($job->titulo));
        $this->assertLessThanOrEqual(1000, mb_strlen($job->descripcion));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $job->titulo.$job->descripcion);
    }

    public function test_a_factura_that_turns_cancelled_asks_for_one_activity_without_amounts_or_rfc(): void
    {
        Queue::fake();
        $respuesta = new ResultadoSat(ResultadoSat::CANCELADO, 'No cancelable', 'Cancelado sin aceptación');
        $this->app->instance(ConsultorSat::class, new class($respuesta) implements ConsultorSat
        {
            public function __construct(public ResultadoSat $respuesta) {}

            public function consultar(string $xml): ResultadoSat
            {
                return $this->respuesta;
            }
        });
        $factura = $this->factura();
        $servicio = app(VerificacionSatService::class);

        $servicio->verificar($factura);
        // Ya cancelada: consultar otra vez no repite la actividad.
        $servicio->verificar($factura);

        Queue::assertPushed(EnviarActividadTickets::class, 1);
        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $this->assertSame('factura_cancelada', $job->evento);
        $this->assertSame('dashboard:factura:'.$factura->id.':cancelada_sat', $job->ref);
        $this->assertNull($job->estacion);
        $this->assertSame('Factura cancelada en el SAT: A 1', $job->titulo);
        $this->assertSame('https://dashboard.ejemplo.test/insumos/facturas/'.$factura->id, $job->url);
        foreach (['GFC051130D29', 'Proveedor Reservado', '108', '949E7F59'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $job->titulo.$job->descripcion.$job->url);
        }
    }

    public function test_a_factura_that_stays_valid_does_not_ask_for_anything(): void
    {
        Queue::fake();
        $this->app->instance(ConsultorSat::class, new class implements ConsultorSat
        {
            public function consultar(string $xml): ResultadoSat
            {
                return new ResultadoSat(ResultadoSat::VIGENTE, 'Cancelable', 'En proceso');
            }
        });

        app(VerificacionSatService::class)->verificar($this->factura());

        Queue::assertNothingPushed();
    }

    public function test_only_enabled_events_are_sent_and_nothing_without_the_bridge(): void
    {
        Queue::fake();
        $estacion = Estacion::factory()->create(['codigo' => '26']);
        $jefe = $this->usuario('Jefe de Zona');
        $servicio = app(SolicitudInsumoService::class);

        config(['ecosistema.actividades.eventos' => ['factura_cancelada']]);
        $servicio->revisar($this->solicitud($estacion), ['accion' => 'devolver', 'comentario_zona' => 'x'], $jefe);
        Queue::assertNotPushed(EnviarActividadTickets::class);

        config(['ecosistema.actividades.eventos' => ['insumos_observada'], 'ecosistema.puente.secreto_salida' => null]);
        $servicio->revisar($this->solicitud($estacion, mes: 11), ['accion' => 'devolver', 'comentario_zona' => 'x'], $jefe);
        Queue::assertNotPushed(EnviarActividadTickets::class);
    }

    public function test_a_failure_while_queueing_never_breaks_the_action(): void
    {
        $estacion = Estacion::factory()->create(['codigo' => '26']);
        $solicitud = $this->solicitud($estacion);
        config(['queue.default' => 'inexistente']);

        app(SolicitudInsumoService::class)->revisar($solicitud, ['accion' => 'devolver', 'comentario_zona' => 'x'], $this->usuario('Jefe de Zona'));

        $this->assertSame(SolicitudInsumo::OBSERVADA, $solicitud->fresh()->estado);
    }

    // --- Trabajo -----------------------------------------------------------------------------------------------------------------------

    public function test_the_job_posts_the_request_and_stores_folio_and_link(): void
    {
        Http::fake([self::ACTIVIDADES => Http::response(['folio' => 'ACT-2026-0012', 'url' => '/activities/12', 'creada' => true], 201)]);
        $solicitud = $this->solicitud(Estacion::factory()->create());

        $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));

        Http::assertSent(function ($request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame([
                'evento' => 'insumos_observada', 'estacion' => '23', 'ref' => 'dashboard:solicitud:5:observada:1790000000',
                'titulo' => 'Solicitud de insumos observada: Tulum', 'descripcion' => 'Corrígela y vuelve a enviarla.',
                'url' => 'https://dashboard.ejemplo.test/insumos/estaciones/3',
            ], json_decode($request->body(), true));

            return $request->hasHeader('X-Puente-Firma');
        });
        $vinculo = EcosistemaVinculo::query()->sole();
        $this->assertSame('ACT-2026-0012', $vinculo->folio);
        $this->assertSame('https://tickets.ejemplo.test/activities/12', $vinculo->url);
        $this->assertSame($solicitud->id, $vinculo->vinculable->id);
        $this->assertSame('ACT-2026-0012', $solicitud->vinculosTickets()->sole()->folio);
    }

    public function test_the_same_ref_never_duplicates_the_link(): void
    {
        Http::fake([self::ACTIVIDADES => Http::sequence()
            ->push(['folio' => 'ACT-2026-0012', 'url' => '/activities/12', 'creada' => true], 201)
            ->push(['folio' => 'ACT-2026-0012', 'url' => '/activities/12', 'creada' => false], 200)]);
        $solicitud = $this->solicitud(Estacion::factory()->create());

        $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));
        $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));

        $this->assertSame(1, EcosistemaVinculo::query()->count());
    }

    public function test_a_hostile_or_malformed_response_never_stores_a_link(): void
    {
        $solicitud = $this->solicitud(Estacion::factory()->create());

        foreach ([
            ['folio' => 'ACT-2026-0012', 'url' => 'https://malo.test/activities/12'],
            ['folio' => 'ACT-2026-0012', 'url' => '/activities/12/../../x'],
            ['folio' => 'ACT-2026-0012', 'url' => '//malo.test/activities/1'],
            ['folio' => '<script>x</script>', 'url' => '/activities/12'],
            ['folio' => 'ACT-2026-0012'],
            ['url' => '/activities/12'],
        ] as $cuerpo) {
            Http::fake([self::ACTIVIDADES => Http::response($cuerpo, 201)]);
            $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));
        }

        $this->assertSame(0, EcosistemaVinculo::query()->count());
    }

    public function test_configuration_rejections_are_not_retried_but_transient_failures_are(): void
    {
        $solicitud = $this->solicitud(Estacion::factory()->create());

        foreach ([['sin_equipo', 422], ['sin_responsable', 422], ['', 404], ['', 403]] as [$error, $estado]) {
            Http::fake([self::ACTIVIDADES => Http::response($error === '' ? [] : ['error' => $error], $estado)]);
            $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));
        }
        $this->assertSame(0, EcosistemaVinculo::query()->count());

        foreach ([Http::response('', 500), Http::response('', 401), Http::response('', 429), Http::response('', 302, ['Location' => 'https://malo.test/x'])] as $respuesta) {
            Http::fake([self::ACTIVIDADES => $respuesta, 'https://malo.test/*' => Http::response(['folio' => 'ACT-2026-0001', 'url' => '/activities/1'], 201)]);

            try {
                $this->trabajo(['id' => $solicitud->id])->handle(app(PuenteCliente::class));
                $this->fail('Debió reintentarse.');
            } catch (RuntimeException $e) {
                $this->assertStringNotContainsString('secreto', $e->getMessage());
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

    // --- Asistencia -------------------------------------------------------------------------------------------------------------------

    public function test_the_attendance_activity_goes_once_per_pending_station_and_day(): void
    {
        Queue::fake();
        $pendiente = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '26', 'is_operativa' => true]);
        $sinPlantilla = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'is_operativa' => true]);
        Empleado::factory()->create(['estacion_id' => $pendiente->id]);

        $this->artisan('asistencia:abrir-actividades')->assertSuccessful();
        $this->artisan('asistencia:abrir-actividades')->assertSuccessful();

        Queue::assertPushed(EnviarActividadTickets::class, 1);
        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $hoy = now('America/Cancun')->toDateString();
        $this->assertSame('asistencia_pendiente', $job->evento);
        $this->assertSame('dashboard:asistencia:'.$pendiente->id.':'.$hoy, $job->ref);
        $this->assertSame('26', $job->estacion);
        $this->assertSame('Asistencia sin capturar: Tulum', $job->titulo);
        $this->assertSame('https://dashboard.ejemplo.test/asistencia/captura', $job->url);
        $this->assertNotSame($sinPlantilla->id, $job->vinculableId);

        $this->travel(1)->day();
        $this->artisan('asistencia:abrir-actividades')->assertSuccessful();
        Queue::assertPushed(EnviarActividadTickets::class, 2);
    }

    public function test_the_attendance_command_is_a_noop_when_off_and_is_scheduled_on_weekdays(): void
    {
        Queue::fake();
        Empleado::factory()->create(['estacion_id' => Estacion::factory()->create(['codigo' => '26', 'is_operativa' => true])->id]);

        config(['ecosistema.actividades.eventos' => []]);
        $this->artisan('asistencia:abrir-actividades')->assertSuccessful();
        config(['ecosistema.actividades.eventos' => ['asistencia_pendiente'], 'ecosistema.puente.secreto_salida' => null]);
        $this->artisan('asistencia:abrir-actividades')->assertSuccessful();
        Queue::assertNothingPushed();

        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'asistencia:abrir-actividades'));
        $this->assertNotNull($evento);
        $this->assertSame('0 12 * * 1-5', $evento->expression);
        $this->assertSame('America/Cancun', (string) $evento->timezone);
    }

    // --- Seguimiento en pantalla ----------------------------------------------------------------------------------------------------

    public function test_the_solicitud_and_factura_pages_show_the_tickets_follow_up_escaped(): void
    {
        $jefe = $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $solicitud = $this->solicitud(Estacion::factory()->create());
        $factura = $this->factura();

        $this->get(route('insumos.show', $solicitud))->assertOk()->assertDontSee('Seguimiento en Tickets');

        foreach ([[$solicitud, 'insumos.show'], [$factura, 'insumos.facturas.show']] as $i => [$modelo, $ruta]) {
            $vinculo = new EcosistemaVinculo(['evento' => 'x', 'ref' => 'ref-'.$i, 'folio' => 'ACT-2026-001'.$i, 'url' => 'https://tickets.ejemplo.test/activities/1'.$i]);
            $vinculo->vinculable()->associate($modelo);
            $vinculo->save();

            $this->get(route($ruta, $modelo))->assertOk()
                ->assertSee('Seguimiento en Tickets')
                ->assertSee('ACT-2026-001'.$i)
                ->assertSee('href="https://tickets.ejemplo.test/activities/1'.$i.'"', false);
        }

        // Un folio con marcado se imprime escapado, nunca como HTML.
        EcosistemaVinculo::query()->where('ref', 'ref-0')->update(['folio' => '<b>X</b>']);
        $this->get(route('insumos.show', $solicitud))->assertOk()->assertSee('&lt;b&gt;X&lt;/b&gt;', false)->assertDontSee('<b>X</b>', false);
        $this->assertNotNull($jefe);
    }
}
