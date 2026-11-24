<?php

namespace Tests\Feature;

use App\Jobs\EnviarActividadTickets;
use App\Models\EcosistemaVinculo;
use App\Models\Estacion;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;
use ZipArchive;

/**
 * Hallazgos de PTAR (problemáticas y propuestas): seguimiento, liga a Tickets, oficio mensual y carga desde el Word.
 */
class PtarHallazgoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $bacalar;

    private PtarPlanta $plantaTulum;

    private PtarPlanta $plantaBacalar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'orden' => 1]);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
        PtarPlanta::sincronizar();
        $this->plantaTulum = PtarPlanta::query()->where('estacion_id', $this->tulum->id)->firstOrFail();
        $this->plantaBacalar = PtarPlanta::query()->where('estacion_id', $this->bacalar->id)->firstOrFail();
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    private function datos(array $extra = []): array
    {
        return $extra + [
            'planta_id' => $this->plantaTulum->id, 'tipo' => 'problematica', 'descripcion' => 'Soplador fuera de servicio',
            'propuesta' => 'Reparar el soplador', 'prioridad' => 'alta', 'estado' => 'abierto',
        ];
    }

    private function hallazgo(PtarPlanta $planta, array $extra = []): PtarHallazgo
    {
        return PtarHallazgo::query()->create($extra + ['planta_id' => $planta->id, 'tipo' => 'problematica', 'descripcion' => 'Fuga en válvula', 'propuesta' => 'Cambiar la válvula']);
    }

    private function activarTickets(): void
    {
        Queue::fake();
        config([
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.actividades.eventos' => ['ptar_hallazgo'],
        ]);
    }

    public function test_zone_registers_a_finding_and_it_is_logged(): void
    {
        $user = $this->con('Jefe de Zona');

        $this->post(route('ptar.hallazgos.store'), $this->datos())->assertRedirect(route('ptar.hallazgos.index'));

        $h = PtarHallazgo::query()->firstOrFail();
        $this->assertSame('abierto', $h->estado);
        $this->assertSame($user->id, $h->capturo_id);
        $this->assertNotNull($h->fecha_detectado);
        $this->assertNull($h->fecha_cierre);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ptar', 'subject_id' => $h->id, 'event' => 'created']);
    }

    public function test_finding_input_is_validated(): void
    {
        $this->con('Jefe de Zona');

        $this->post(route('ptar.hallazgos.store'), $this->datos(['descripcion' => '']))->assertInvalid('descripcion');
        $this->post(route('ptar.hallazgos.store'), $this->datos(['tipo' => 'otro']))->assertInvalid('tipo');
        $this->post(route('ptar.hallazgos.store'), $this->datos(['prioridad' => 'urgente']))->assertInvalid('prioridad');
        $this->post(route('ptar.hallazgos.store'), $this->datos(['equipo' => 'turbina']))->assertInvalid('equipo');
        $this->post(route('ptar.hallazgos.store'), $this->datos(['planta_id' => 9999]))->assertInvalid('planta_id');
        $this->post(route('ptar.hallazgos.store'), $this->datos(['fecha_compromiso' => '31/12/2026']))->assertInvalid('fecha_compromiso');
        $this->assertSame(0, PtarHallazgo::query()->count());
    }

    public function test_closing_sets_the_closing_date_and_reopening_clears_it(): void
    {
        $this->con('Jefe de Zona');
        $h = $this->hallazgo($this->plantaTulum);

        $this->put(route('ptar.hallazgos.update', $h), $this->datos(['estado' => 'cerrado']) + [])->assertInvalid('planta_id');
        $datos = $this->datos(['estado' => 'cerrado']);
        unset($datos['planta_id']);
        $this->put(route('ptar.hallazgos.update', $h), $datos)->assertRedirect();

        $h->refresh();
        $this->assertSame('cerrado', $h->estado);
        $this->assertSame(now('America/Cancun')->toDateString(), $h->fecha_cierre->toDateString());

        $datos['estado'] = 'en_proceso';
        $this->put(route('ptar.hallazgos.update', $h), $datos)->assertRedirect();
        $this->assertNull($h->refresh()->fecha_cierre);
    }

    public function test_station_only_sees_its_own_findings_and_cannot_manage(): void
    {
        $propio = $this->hallazgo($this->plantaTulum, ['descripcion' => 'Hallazgo de Tulum']);
        $this->hallazgo($this->plantaBacalar, ['descripcion' => 'Hallazgo de Bacalar']);

        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('ptar.hallazgos.index'))->assertOk()->assertSee('Hallazgo de Tulum')->assertDontSee('Hallazgo de Bacalar')->assertDontSee('Registrar un hallazgo');
        $this->post(route('ptar.hallazgos.store'), $this->datos())->assertForbidden();
        $this->put(route('ptar.hallazgos.update', $propio), ['estado' => 'cerrado'])->assertForbidden();
        $this->post(route('ptar.hallazgos.tickets', $propio))->assertForbidden();
        $this->get(route('ptar.oficio.create'))->assertForbidden();
    }

    public function test_filters_narrow_the_list(): void
    {
        $this->con('Edificio');
        $this->hallazgo($this->plantaTulum, ['descripcion' => 'Abierto de Tulum']);
        $this->hallazgo($this->plantaBacalar, ['descripcion' => 'Cerrado de Bacalar', 'estado' => 'cerrado']);

        $this->get(route('ptar.hallazgos.index'))->assertSee('Abierto de Tulum')->assertDontSee('Cerrado de Bacalar');
        $this->get(route('ptar.hallazgos.index', ['estado' => 'todos']))->assertSee('Cerrado de Bacalar');
        $this->get(route('ptar.hallazgos.index', ['estado' => 'cerrado']))->assertDontSee('Abierto de Tulum');
        $this->get(route('ptar.hallazgos.index', ['estado' => 'todos', 'planta' => $this->plantaTulum->id]))->assertDontSee('Cerrado de Bacalar');
        $this->get(route('ptar.hallazgos.index', ['estado' => 'raro']))->assertInvalid('estado');
    }

    public function test_sending_to_tickets_queues_one_activity_and_cannot_be_repeated(): void
    {
        $this->activarTickets();
        $this->con('Jefe de Zona');
        $h = $this->hallazgo($this->plantaTulum);

        $this->post(route('ptar.hallazgos.tickets', $h))->assertRedirect();

        Queue::assertPushed(EnviarActividadTickets::class, 1);
        $job = Queue::pushed(EnviarActividadTickets::class)->first();
        $this->assertSame('ptar_hallazgo', $job->evento);
        $this->assertSame('dashboard:ptar_hallazgo:'.$h->id, $job->ref);
        $this->assertSame('25', $job->estacion);
        $this->assertSame('https://dashboard.ejemplo.test/ptar/hallazgos', $job->url);
        $this->assertNotNull($h->refresh()->tickets_enviado_at);

        $this->post(route('ptar.hallazgos.tickets', $h))->assertSessionHasErrors('tickets');
        Queue::assertPushed(EnviarActividadTickets::class, 1);
    }

    public function test_a_finding_without_folio_can_be_resent_after_an_hour(): void
    {
        $this->activarTickets();
        $this->con('Jefe de Zona');
        $h = $this->hallazgo($this->plantaTulum, ['tickets_enviado_at' => now()->subMinutes(10)]);

        $this->post(route('ptar.hallazgos.tickets', $h))->assertSessionHasErrors('tickets');
        Queue::assertNothingPushed();

        $h->update(['tickets_enviado_at' => now()->subHours(2)]);
        $this->get(route('ptar.hallazgos.index'))->assertSee('Reintentar envío a Tickets');
        $this->post(route('ptar.hallazgos.tickets', $h))->assertRedirect()->assertSessionHasNoErrors();
        Queue::assertPushed(EnviarActividadTickets::class, 1);
    }

    public function test_sending_to_tickets_is_refused_when_unavailable_or_closed(): void
    {
        Queue::fake();
        config(['ecosistema.puente.secreto_salida' => null]);
        $this->con('Jefe de Zona');
        $h = $this->hallazgo($this->plantaTulum);

        $this->post(route('ptar.hallazgos.tickets', $h))->assertSessionHasErrors('tickets');

        $this->activarTickets();
        $h->update(['estado' => 'cerrado']);
        $this->post(route('ptar.hallazgos.tickets', $h))->assertSessionHasErrors('tickets');
        Queue::assertNothingPushed();
    }

    public function test_ticket_folio_is_shown_next_to_the_finding(): void
    {
        $this->con('Jefe de Zona');
        $h = $this->hallazgo($this->plantaTulum);
        $vinculo = new EcosistemaVinculo(['evento' => 'ptar_hallazgo', 'ref' => 'dashboard:ptar_hallazgo:'.$h->id, 'folio' => 'ACT-2026-0042', 'url' => 'https://tickets.zonaorientetm.tech/activities/42']);
        $vinculo->vinculable()->associate($h);
        $vinculo->save();

        $this->get(route('ptar.hallazgos.index'))->assertOk()->assertSee('ACT-2026-0042');
    }

    public function test_monthly_oficio_lists_open_findings_per_plant_and_downloads_a_word_file(): void
    {
        $this->con('Jefe de Zona');
        $this->hallazgo($this->plantaTulum, ['descripcion' => 'Coloración amarilla en el agua', 'propuesta' => 'Análisis en laboratorio EMA']);
        $this->hallazgo($this->plantaTulum, ['tipo' => 'propuesta', 'descripcion' => 'Instalar cubierta en la planta', 'propuesta' => null]);
        $this->hallazgo($this->plantaBacalar, ['descripcion' => 'Tubería rota ya reparada', 'estado' => 'cerrado']);

        $this->get(route('ptar.oficio.create'))->assertOk()->assertSee('Tulum')->assertDontSee('Tubería rota');

        $respuesta = $this->post(route('ptar.oficio.store'), [
            'leyenda' => '"2026, Año de prueba"', 'numero' => 'TM/UAI/CGGIF/DGTZO/0100', 'fecha' => '2026-11-24',
            'destinatario' => 'C. Destinatario de Prueba', 'asunto' => 'Presentar las acciones de propuesta.',
            'consideraciones' => "Primer párrafo de la consideración.\n\nSegundo párrafo.",
            'firmante_cargo' => 'Residencia profesional', 'firmante_nombre' => 'C. Firmante de Prueba',
        ]);

        $respuesta->assertOk()->assertDownload('ESTATUS_PTAR_241126.docx');
        $archivo = $respuesta->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($archivo));
        $texto = strip_tags(str_replace('</w:p>', "\n", (string) $zip->getFromName('word/document.xml')));
        $zip->close();

        foreach (['2026, Año de prueba', 'No. TM/UAI/CGGIF/DGTZO/0100', '24 Nov. 2026', 'C. Destinatario de Prueba', '1. ASUNTO', '2. INFORMACIÓN', 'a) Tulum.', 'Problemática.', 'Coloración amarilla en el agua', 'Propuestas.', 'Análisis en laboratorio EMA', 'Instalar cubierta en la planta', '3. CONSIDERACIÓN', 'Segundo párrafo.', 'Respetuosamente', 'C. Firmante de Prueba'] as $esperado) {
            $this->assertStringContainsString($esperado, $texto);
        }
        $this->assertStringNotContainsString('Tubería rota', $texto);
        $this->assertStringNotContainsString('Bacalar', $texto);
    }

    public function test_oficio_requires_the_signature_data(): void
    {
        $this->con('Jefe de Zona');

        $this->post(route('ptar.oficio.store'), ['leyenda' => 'x', 'numero' => 'TM/1', 'fecha' => '2026-11-24'])->assertInvalid(['destinatario', 'asunto', 'firmante_cargo', 'firmante_nombre']);
    }

    public function test_findings_load_from_the_word_oficio_without_duplicating(): void
    {
        $word = new PhpWord;
        $seccion = $word->addSection();
        foreach (['"2026, Año de prueba"', '(24 Jul. 2026)', 'ASUNTO', 'Presentar las acciones.', 'INFORMACIÓN', 'Tulum.', 'Problemática.', 'Coloración amarilla en el agua.', 'Propuesta.', 'Análisis en laboratorio.', 'Nicolás Bravo- Kohunlich.', 'Problemáticas.', 'Tanque 1 fuera de servicio.', 'Acciones.', 'Dar mantenimiento al tanque.', 'CONSIDERACIÓN', 'Texto general que no es hallazgo.'] as $linea) {
            $seccion->addText($linea);
        }
        $archivo = tempnam(sys_get_temp_dir(), 'ofi').'.docx';
        IOFactory::createWriter($word, 'Word2007')->save($archivo);
        Estacion::factory()->create(['nombre' => 'Kohunlich', 'codigo' => '31', 'orden' => 4]);

        $this->artisan('ptar:cargar-hallazgos', ['archivo' => $archivo, '--simular' => true])
            ->expectsOutputToContain('Simulación: 4 hallazgo(s) con fecha 2026-07-24')->assertSuccessful();
        $this->assertSame(0, PtarHallazgo::query()->count());

        $this->artisan('ptar:cargar-hallazgos', ['archivo' => $archivo])->expectsOutputToContain('Creados: 4')->assertSuccessful();
        $this->artisan('ptar:cargar-hallazgos', ['archivo' => $archivo])->expectsOutputToContain('Creados: 0; omitidos por existir: 4')->assertSuccessful();

        $this->assertSame(4, PtarHallazgo::query()->count());
        $this->assertSame(2, PtarHallazgo::query()->where('tipo', 'propuesta')->count());
        $this->assertSame('2026-07-24', PtarHallazgo::query()->first()->fecha_detectado->toDateString());
        $this->assertSame(1, PtarHallazgo::query()->whereHas('planta', fn ($q) => $q->where('numero', 31))->where('tipo', 'propuesta')->count());

        @unlink($archivo);
    }
}
