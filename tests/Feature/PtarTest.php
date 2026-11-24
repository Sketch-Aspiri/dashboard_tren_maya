<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\PtarEquipo;
use App\Models\PtarHallazgo;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Models\User;
use App\Services\Ptar\PtarService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * PTAR (plantas de tratamiento de aguas residuales): catálogo de plantas, permisos y tablero del mes.
 */
class PtarTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $bacalar;

    private Estacion $eze;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'orden' => 1]);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
        $this->eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'orden' => 10]);
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    public function test_sync_creates_only_the_plants_of_existing_stations_and_is_idempotent(): void
    {
        $this->assertSame(3, PtarPlanta::sincronizar());
        $this->assertSame(0, PtarPlanta::sincronizar());

        $planta = PtarPlanta::query()->where('estacion_id', $this->bacalar->id)->firstOrFail();
        $this->assertSame(29, $planta->numero);
        $this->assertSame(['NOM-001-SEMARNAT-2021', 'NOM-003-SEMARNAT-1997', 'NOM-004-SEMARNAT-2002'], $planta->normas);
        $this->assertSame('PCZ', PtarPlanta::query()->where('estacion_id', $this->eze->id)->value('tramo'));
    }

    public function test_zone_sees_the_board_with_every_plant(): void
    {
        $this->con('Jefe de Zona');
        PtarRegistro::query()->create([
            'estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => 'enviada', 'modo' => 'manual',
        ]);
        PtarHallazgo::query()->create([
            'planta_id' => PtarPlanta::query()->firstOrCreate(['estacion_id' => $this->tulum->id])->id,
            'descripcion' => 'Soplador fuera de servicio',
        ]);

        $this->get(route('ptar.index', ['anio' => 2026, 'mes' => 11]))
            ->assertOk()
            ->assertSee('Bacalar')
            ->assertSee('Tulum')
            ->assertSee('Edificio Zonal Este')
            ->assertSee('Manual');
    }

    public function test_station_and_edificio_can_open_the_board_but_an_account_without_role_cannot(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->get(route('ptar.index'))->assertRedirect(route('ptar.estacion', ['estacion' => $this->tulum->id, 'anio' => now('America/Cancun')->year, 'mes' => now('America/Cancun')->month]));

        $this->con('Edificio', ['estacion_id' => $this->eze->id]);
        $this->get(route('ptar.index'))->assertOk();

        $sinRol = User::factory()->create();
        $this->actingAsTwoFactorVerified($sinRol);
        $this->get(route('ptar.index'))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('ptar.index'))->assertRedirect(route('login'));
    }

    public function test_policy_limits_capture_to_the_own_station_while_editable(): void
    {
        $user = $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $propio = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11])->fresh();
        $ajeno = PtarRegistro::query()->create(['estacion_id' => $this->bacalar->id, 'anio' => 2026, 'mes' => 11])->fresh();

        $this->assertTrue($user->can('update', $propio));
        $this->assertFalse($user->can('update', $ajeno));
        $this->assertFalse($user->can('view', $ajeno));

        $propio->update(['estado' => PtarRegistro::ENVIADA]);
        $this->assertFalse($user->can('update', $propio->fresh()));
        $this->assertFalse($user->can('review', $propio->fresh()));
    }

    public function test_only_the_zone_reviews_sent_records_and_manages(): void
    {
        $registro = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => 'enviada']);

        $jefe = $this->con('Jefe de Zona');
        $this->assertTrue($jefe->can('review', $registro));
        $this->assertTrue($jefe->can('gestionar', PtarRegistro::class));

        $estacion = $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->assertFalse($estacion->can('gestionar', PtarRegistro::class));
    }

    public function test_registro_changes_are_logged(): void
    {
        $registro = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]);
        $registro->update(['modo' => 'automatico']);

        $this->assertDatabaseHas('activity_log', ['log_name' => 'ptar', 'subject_id' => $registro->id, 'event' => 'updated']);
    }

    private function abrir(Estacion $estacion, int $anio = 2026, int $mes = 11): PtarRegistro
    {
        $this->get(route('ptar.estacion', ['estacion' => $estacion->id, 'anio' => $anio, 'mes' => $mes]))->assertRedirect();

        return PtarRegistro::query()->where(['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes])->firstOrFail();
    }

    private function captura(PtarRegistro $registro, array $extra = []): array
    {
        return $extra + [
            'modo' => 'automatico', 'operador' => 'ACQUASU', 'observaciones_generales' => 'Sin novedad',
            'equipos' => ['sopladores' => ['estatus' => 'operando', 'observaciones' => 'ok']],
            'actividades' => [$registro->actividades->first()->id => ['ultima_fecha' => '2026-10-15', 'nota' => 'limpieza']],
        ];
    }

    public function test_station_opens_its_record_with_equipment_and_default_activities(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('ptar.index', ['anio' => 2026, 'mes' => 11]))->assertRedirect();
        $registro = $this->abrir($this->tulum);

        $this->assertSame('borrador', $registro->estado);
        $this->assertCount(5, $registro->equipos);
        $this->assertSame(['Cárcamo', 'Tolva', 'Canaletas'], $registro->actividades->pluck('actividad')->all());
        $this->get(route('ptar.show', $registro))->assertOk()->assertSee('Bomba sumergible');
    }

    public function test_station_cannot_open_another_stations_record(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('ptar.estacion', $this->bacalar))->assertForbidden();
    }

    public function test_next_month_inherits_mode_equipment_status_and_maintenance_dates(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $octubre = $this->abrir($this->tulum, 2026, 10);
        $this->put(route('ptar.guardar', $octubre), $this->captura($octubre, ['modo' => 'manual']))->assertRedirect();

        $noviembre = $this->abrir($this->tulum, 2026, 11);

        $this->assertSame('manual', $noviembre->modo);
        $this->assertSame('operando', $noviembre->equipos->firstWhere('equipo', 'sopladores')->estatus);
        $this->assertNull($noviembre->equipos->firstWhere('equipo', 'sopladores')->observaciones);
        $this->assertSame('2026-10-15', $noviembre->actividades->first()->ultima_fecha->toDateString());
        $this->assertNull($noviembre->observaciones_generales);
    }

    public function test_station_saves_the_capture_and_it_is_logged(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);

        $this->put(route('ptar.guardar', $registro), $this->captura($registro))->assertRedirect(route('ptar.show', $registro));

        $registro->refresh();
        $this->assertSame('automatico', $registro->modo);
        $this->assertSame('Sin novedad', $registro->observaciones_generales);
        $this->assertSame('limpieza', $registro->actividades->first()->nota);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ptar', 'description' => 'captura guardada']);
    }

    public function test_capture_validates_input_and_ignores_unknown_fields(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);

        $this->put(route('ptar.guardar', $registro), $this->captura($registro, ['modo' => 'raro']))->assertInvalid('modo');
        $this->put(route('ptar.guardar', $registro), $this->captura($registro, ['equipos' => ['sopladores' => ['estatus' => 'roto']]]))->assertInvalid('equipos.sopladores.estatus');
        $this->put(route('ptar.guardar', $registro), $this->captura($registro, ['actividades' => [$registro->actividades->first()->id => ['ultima_fecha' => '2999-01-01']]]))->assertInvalid();

        $this->put(route('ptar.guardar', $registro), $this->captura($registro, ['estado' => 'aprobada', 'estacion_id' => $this->bacalar->id]))->assertRedirect();
        $registro->refresh();
        $this->assertSame('borrador', $registro->estado);
        $this->assertSame($this->tulum->id, $registro->estacion_id);
    }

    public function test_station_cannot_save_another_stations_record(): void
    {
        $ajeno = PtarRegistro::query()->create(['estacion_id' => $this->bacalar->id, 'anio' => 2026, 'mes' => 11]);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->put(route('ptar.guardar', $ajeno), ['modo' => 'manual'])->assertForbidden();
        $this->get(route('ptar.show', $ajeno))->assertForbidden();
    }

    public function test_photo_upload_is_limited_to_images_and_to_the_monthly_maximum(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);

        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])->assertInvalid('foto');

        config(['ptar.max_evidencias' => 2]);
        foreach (['a.jpg', 'b.png'] as $nombre) {
            $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image($nombre), 'pie' => 'Cárcamo'])->assertRedirect();
        }
        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image('c.jpg')])->assertSessionHasErrors('foto');

        $this->assertSame(2, $registro->evidencias()->count());
        Storage::disk('local')->assertExists($registro->evidencias()->first()->ruta);
    }

    public function test_photo_is_served_only_to_people_who_can_see_the_record_and_can_be_removed(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);
        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image('a.jpg')]);
        $foto = $registro->evidencias()->firstOrFail();

        $this->get(route('ptar.evidencia', [$registro, $foto]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->con('Estación', ['estacion_id' => $this->bacalar->id]);
        $this->get(route('ptar.evidencia', [$registro, $foto]))->assertForbidden();

        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->delete(route('ptar.evidencias.destroy', [$registro, $foto]))->assertRedirect();
        $this->assertSame(0, $registro->evidencias()->count());
        Storage::disk('local')->assertMissing($foto->ruta);
    }

    public function test_sending_requires_operation_mode_and_a_photo_then_locks_the_record(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);

        $this->post(route('ptar.enviar', $registro))->assertSessionHasErrors('envio');

        $this->put(route('ptar.guardar', $registro), $this->captura($registro));
        $this->post(route('ptar.enviar', $registro))->assertSessionHasErrors('envio');

        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image('a.jpg')]);
        $this->post(route('ptar.enviar', $registro))->assertRedirect();

        $this->assertSame('enviada', $registro->refresh()->estado);
        $this->put(route('ptar.guardar', $registro), $this->captura($registro))->assertForbidden();
        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image('b.jpg')])->assertForbidden();
    }

    public function test_zone_returns_with_a_comment_then_station_resends_and_zone_approves(): void
    {
        $estacion = $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $registro = $this->abrir($this->tulum);
        $this->put(route('ptar.guardar', $registro), $this->captura($registro));
        $this->post(route('ptar.evidencias.store', $registro), ['foto' => UploadedFile::fake()->image('a.jpg')]);
        $this->post(route('ptar.enviar', $registro));

        $jefe = $this->con('Jefe de Zona');
        $this->post(route('ptar.revisar', $registro), ['accion' => 'devolver'])->assertInvalid('comentario_zona');
        $this->post(route('ptar.revisar', $registro), ['accion' => 'devolver', 'comentario_zona' => 'Falta la fecha del cárcamo'])->assertRedirect();
        $registro->refresh();
        $this->assertSame('observada', $registro->estado);
        $this->assertSame($jefe->id, $registro->revisada_por);

        $this->actingAsTwoFactorVerified($estacion);
        $this->get(route('ptar.show', $registro))->assertOk()->assertSee('Falta la fecha del cárcamo');
        $this->post(route('ptar.enviar', $registro))->assertRedirect();

        $this->actingAsTwoFactorVerified($jefe);
        $this->post(route('ptar.revisar', $registro), ['accion' => 'aprobar'])->assertRedirect();
        $this->assertSame('aprobada', $registro->refresh()->estado);
        $this->post(route('ptar.revisar', $registro), ['accion' => 'aprobar'])->assertForbidden();
    }

    public function test_station_cannot_review_and_edificio_captures_only_its_own_station(): void
    {
        $registro = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'estado' => 'enviada']);
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $this->post(route('ptar.revisar', $registro), ['accion' => 'aprobar'])->assertForbidden();

        $this->con('Edificio', ['estacion_id' => $this->eze->id]);
        $propio = $this->abrir($this->eze);
        $this->put(route('ptar.guardar', $propio), $this->captura($propio))->assertRedirect();
        $ajeno = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 10]);
        $this->put(route('ptar.guardar', $ajeno), ['modo' => 'manual'])->assertForbidden();
    }

    public function test_zone_opening_a_station_never_creates_its_record_but_follows_an_existing_one(): void
    {
        $this->con('Jefe de Zona');

        $this->get(route('ptar.estacion', ['estacion' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]))
            ->assertRedirect(route('ptar.index', ['anio' => 2026, 'mes' => 11]))
            ->assertSessionHas('status');
        $this->assertSame(0, PtarRegistro::query()->count());

        $existente = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]);
        $this->get(route('ptar.estacion', ['estacion' => $this->tulum->id, 'anio' => 2026, 'mes' => 11]))->assertRedirect(route('ptar.show', $existente));
        $this->assertSame(1, PtarRegistro::query()->count());
    }

    public function test_board_offers_capture_only_where_the_user_can_capture(): void
    {
        $this->con('Jefe de Zona');
        $this->get(route('ptar.index', ['anio' => 2026, 'mes' => 11]))->assertOk()->assertDontSee('Capturar');

        $this->con('Edificio', ['estacion_id' => $this->eze->id]);
        $this->get(route('ptar.index', ['anio' => 2026, 'mes' => 11]))->assertOk()->assertSee('Capturar');
    }

    public function test_traffic_light_follows_mode_and_equipment_status(): void
    {
        $servicio = app(PtarService::class);
        $registro = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'modo' => 'automatico'])->fresh();
        foreach (array_keys(config('ptar.equipos')) as $clave) {
            $registro->equipos()->create(['equipo' => $clave, 'estatus' => PtarEquipo::OPERANDO]);
        }

        $this->assertSame('gris', $servicio->semaforo(null));
        $this->assertSame('verde', $servicio->semaforo($registro->load('equipos')));

        $registro->update(['modo' => 'manual']);
        $this->assertSame('ambar', $servicio->semaforo($registro->load('equipos')));

        $registro->equipos()->where('equipo', 'sopladores')->update(['estatus' => PtarEquipo::FUERA_SERVICIO]);
        $this->assertSame('rojo', $servicio->semaforo($registro->load('equipos')));
    }

    public function test_board_flags_overdue_maintenance(): void
    {
        $this->con('Jefe de Zona');
        $registro = PtarRegistro::query()->create(['estacion_id' => $this->tulum->id, 'anio' => 2026, 'mes' => 11, 'modo' => 'automatico']);
        $registro->actividades()->create(['actividad' => 'Cárcamo', 'ultima_fecha' => now()->subDays(200)]);
        $registro->actividades()->create(['actividad' => 'Tolva', 'ultima_fecha' => now()->subDays(5)]);

        $fila = app(PtarService::class)->tablero(2026, 11)->firstWhere('estacion.nombre', 'Tulum');

        $this->assertSame(1, $fila['vencidas']);
    }
}
