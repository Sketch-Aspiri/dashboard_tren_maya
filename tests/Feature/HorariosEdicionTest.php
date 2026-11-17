<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoTickets;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Horarios\HorarioEdicionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Crear el horario dentro del sistema (borrador, copiar el mes anterior, rellenar, personas, publicar)
 * y editarlo desde la vista: la estación propone (la Zona autoriza, por lote) y la Zona guarda directo.
 */
class HorariosEdicionTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $morelos;

    private Estacion $tulum;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->morelos = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23']);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25']);
    }

    private function usuario(string $rol, ?Estacion $estacion = null, ?string $correo = null): User
    {
        $user = User::factory()->create(array_filter(['estacion_id' => $estacion?->id, 'email' => $correo, 'sso_vinculado_at' => $correo ? now() : null]));
        $user->assignRole($rol);

        return $user;
    }

    private function activarAvisos(): void
    {
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
        Queue::fake();
    }

    private function empleado(Estacion $estacion, string $no, string $nombre, string $estatus = 'activo'): Empleado
    {
        return Empleado::factory()->create(['no_empleado' => $no, 'nombre_completo' => $nombre, 'estacion_id' => $estacion->id, 'estatus' => $estatus]);
    }

    /**
     * Horario ya publicado con turnos del 7 al 13 de diciembre de 2026 (lunes a domingo).
     *
     * @param  array<string, array{0: Estacion, 1: string}>  $personas  no_empleado => [estación, nombre]
     */
    private function publicado(array $personas = [], int $anio = 2026, int $mes = 12, ?string $inicio = '2026-12-07', int $dias = 7): HorarioPeriodo
    {
        $periodo = HorarioPeriodo::query()->create(['anio' => $anio, 'mes' => $mes, 'version' => 1, 'estado' => HorarioPeriodo::VIGENTE, 'origen' => 'excel', 'publicado_at' => now()]);
        $personas = $personas ?: ['241232' => [$this->morelos, 'Ana Morelos'], '241300' => [$this->morelos, 'Beto Morelos'], '240428' => [$this->tulum, 'Carla Tulum']];

        foreach ($personas as $no => [$estacion, $nombre]) {
            for ($i = 0; $i < $dias; $i++) {
                $fecha = CarbonImmutable::parse($inicio)->addDays($i);
                HorarioTurno::query()->create([
                    'periodo_id' => $periodo->id, 'estacion_id' => $estacion->id, 'no_empleado' => $no, 'nombre' => $nombre,
                    'fecha' => $fecha->toDateString(), 'jornada' => $fecha->isMonday() ? 'LUNES' : '08:00 a 16:00', 'comida' => '13:00 a 14:00', 'origen' => 'oficial',
                ]);
            }
        }

        return $periodo;
    }

    private function turno(string $no, string $fecha, ?HorarioPeriodo $periodo = null): HorarioTurno
    {
        return HorarioTurno::query()->when($periodo, fn ($q) => $q->where('periodo_id', $periodo->id))->where('no_empleado', $no)->whereDate('fecha', $fecha)->firstOrFail();
    }

    // === A. Crear el horario desde el sistema ====================================================

    public function test_zone_creates_a_blank_draft_with_the_active_staff_of_each_estacion(): void
    {
        $this->empleado($this->morelos, '241232', 'Ana Morelos');
        $this->empleado($this->morelos, '241300', 'Beto Morelos', 'vacante');
        $this->empleado($this->tulum, '240428', 'Carla Tulum');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco'])
            ->assertRedirect(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'editar' => 1]));

        $periodo = HorarioPeriodo::query()->sole();
        $this->assertSame(['borrador', 'sistema', 1], [$periodo->estado, $periodo->origen, $periodo->version]);
        $this->assertSame(31, HorarioTurno::query()->count(), 'Solo la persona activa de la estación elegida, un turno por día.');
        $this->assertSame(['241232', ''], [HorarioTurno::query()->first()->no_empleado, HorarioTurno::query()->first()->jornada]);
        $this->assertSame($this->morelos->id, HorarioTurno::query()->first()->estacion_id);
    }

    public function test_zone_copies_the_previous_month_keeping_the_weekly_pattern(): void
    {
        $this->publicado(['241232' => [$this->morelos, 'Ana Morelos']], 2026, 11, '2026-11-01', 30);
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'copiar'])->assertSessionHasNoErrors();

        $diciembre = HorarioPeriodo::query()->where('mes', 12)->sole();
        $this->assertSame(31, HorarioTurno::query()->where('periodo_id', $diciembre->id)->count());
        // 7 dic (lunes) = lunes 9 nov; 31 dic (jueves) no tiene el 3 dic de noviembre, así que toma el 26 nov.
        $this->assertSame('LUNES', $this->turno('241232', '2026-12-07', $diciembre)->jornada);
        $this->assertSame('08:00 a 16:00', $this->turno('241232', '2026-12-31', $diciembre)->jornada);
        $this->assertSame('13:00 a 14:00', $this->turno('241232', '2026-12-31', $diciembre)->comida);
    }

    public function test_cannot_create_when_the_month_already_has_a_schedule_or_nothing_to_copy(): void
    {
        $this->empleado($this->morelos, '241232', 'Ana Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'copiar'])->assertInvalid('estaciones');
        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [], 'modo' => 'blanco'])->assertInvalid('estaciones');

        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco'])->assertSessionHasNoErrors();
        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco'])->assertInvalid('mes');
        $this->assertSame(1, HorarioPeriodo::query()->count());
    }

    public function test_estacion_cannot_create_or_manage_drafts(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $periodo = $this->publicado();

        $this->get(route('horarios.crear.create'))->assertForbidden();
        $this->post(route('horarios.crear.store'), ['anio' => 2027, 'mes' => 1, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco'])->assertForbidden();
        $this->post(route('horarios.periodos.publicar', $periodo))->assertForbidden();
        $this->delete(route('horarios.periodos.descartar', $periodo))->assertForbidden();
        $this->post(route('horarios.periodos.rellenar', $periodo), ['no_empleado' => '241232', 'desde' => '2026-12-07', 'hasta' => '2026-12-13', 'dias' => [1], 'jornada' => 'X'])->assertForbidden();
        $this->post(route('horarios.periodos.personas.store', $periodo), ['estacion_id' => $this->morelos->id, 'no_empleado' => '9', 'nombre' => 'Nuevo'])->assertForbidden();
        $this->post(route('horarios.periodos.personas.quitar', [$periodo, '241232']))->assertForbidden();
    }

    public function test_draft_is_invisible_to_estaciones_and_the_consolidado_until_published_which_notifies_each_estacion(): void
    {
        $this->activarAvisos();
        $this->empleado($this->morelos, '241232', 'Ana Morelos');
        $this->empleado($this->tulum, '240428', 'Carla Tulum');
        $this->usuario('Estación', $this->morelos, 'morelos@ejemplo.test');
        $this->usuario('Estación', $this->tulum, 'tulum@ejemplo.test');

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id, $this->tulum->id], 'modo' => 'blanco']);
        $periodo = HorarioPeriodo::query()->sole();

        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertSee('Este horario es un borrador')->assertSee('Ana Morelos');
        $this->artisan('horarios:consolidado-semanal', ['--semana' => '2026-12-07'])->assertSuccessful();
        $this->assertSame(0, HorarioConsolidado::query()->sole()->turnos);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertDontSee('Ana Morelos')->assertSee('Todavía no hay un horario publicado');

        Queue::fake();
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.periodos.publicar', $periodo))->assertSessionHasNoErrors();
        Queue::assertPushed(EnviarAvisoTickets::class, 2);
        $this->assertSame('vigente', $periodo->fresh()->estado);
        $this->post(route('horarios.periodos.publicar', $periodo))->assertInvalid('periodo');

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertSee('Ana Morelos');
    }

    public function test_draft_can_be_discarded_but_a_published_schedule_cannot(): void
    {
        $this->empleado($this->morelos, '241232', 'Ana Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco']);
        $borrador = HorarioPeriodo::query()->sole();

        $this->delete(route('horarios.periodos.descartar', $borrador))->assertRedirect(route('horarios.crear.create'));
        $this->assertSame([0, 0], [HorarioPeriodo::query()->count(), HorarioTurno::query()->count()]);

        $vigente = $this->publicado();
        $this->delete(route('horarios.periodos.descartar', $vigente))->assertInvalid('periodo');
        $this->assertSame(1, HorarioPeriodo::query()->count());
    }

    public function test_fill_by_pattern_sets_the_chosen_weekdays_in_the_range(): void
    {
        $periodo = $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.periodos.rellenar', $periodo), ['no_empleado' => '241232', 'desde' => '2026-12-07', 'hasta' => '2026-12-13', 'dias' => [2, 4], 'jornada' => 'DESCANSO'])->assertSessionHasNoErrors();
        $this->post(route('horarios.periodos.rellenar', $periodo), ['no_empleado' => '241232', 'desde' => '2026-12-07', 'hasta' => '2026-12-13', 'dias' => [], 'jornada' => 'X'])->assertInvalid('dias');

        $this->assertSame('DESCANSO', $this->turno('241232', '2026-12-08')->jornada);
        $this->assertSame('DESCANSO', $this->turno('241232', '2026-12-10')->jornada);
        $this->assertSame('ajustado', $this->turno('241232', '2026-12-08')->origen);
        $this->assertSame('08:00 a 16:00', $this->turno('241232', '2026-12-09')->jornada);
        $this->assertSame('oficial', $this->turno('241232', '2026-12-09')->origen);
        $this->assertSame('08:00 a 16:00', $this->turno('241300', '2026-12-08')->jornada, 'Las demás personas no cambian.');
    }

    public function test_zone_adds_and_removes_people_and_removing_cancels_their_pending_requests(): void
    {
        $periodo = $this->publicado();
        $nuevo = $this->empleado($this->morelos, '999001', 'Diana Directorio');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.periodos.personas.store', $periodo), ['empleado_id' => $nuevo->id, 'estacion_id' => $this->morelos->id])->assertSessionHasNoErrors();
        $this->post(route('horarios.periodos.personas.store', $periodo), ['estacion_id' => $this->morelos->id, 'no_empleado' => '999002', 'nombre' => 'Manuel Manual', 'puesto' => 'Operador'])->assertSessionHasNoErrors();
        $this->post(route('horarios.periodos.personas.store', $periodo), ['estacion_id' => $this->morelos->id, 'no_empleado' => '999002', 'nombre' => 'Repetido'])->assertInvalid('no_empleado');

        $this->assertSame(31, HorarioTurno::query()->where('periodo_id', $periodo->id)->where('no_empleado', '999001')->count());
        $this->assertSame('Diana Directorio', HorarioTurno::query()->where('no_empleado', '999001')->first()->nombre);
        $this->assertSame($nuevo->id, HorarioTurno::query()->where('no_empleado', '999001')->first()->empleado_id);
        $this->assertSame('Operador', HorarioTurno::query()->where('no_empleado', '999002')->first()->puesto);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$this->turno('241232', '2026-12-08')->id => ['jornada' => 'DESCANSO', 'comida' => '']], 'motivo' => 'Motivo suficientemente largo']);
        $this->assertSame(1, HorarioSolicitud::query()->where('estado', 'pendiente')->count());

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.periodos.personas.quitar', [$periodo, '241232']))->assertSessionHasNoErrors();

        $this->assertSame(0, HorarioTurno::query()->where('no_empleado', '241232')->count());
        $this->assertSame('cancelada', HorarioSolicitud::query()->sole()->estado);
    }

    // === B. Editar desde la vista ==================================================================

    public function test_estacion_edit_creates_pending_requests_in_one_lote_and_the_table_does_not_change(): void
    {
        $this->activarAvisos();
        $periodo = $this->publicado();
        $this->usuario('Edificio', null, 'edificio@ejemplo.test');
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $a = $this->turno('241232', '2026-12-08');
        $b = $this->turno('241232', '2026-12-09');
        $c = $this->turno('241300', '2026-12-09');

        $this->post(route('horarios.cambios.proponer'), [
            'turnos' => [
                $a->id => ['jornada' => 'DESCANSO', 'comida' => '13:00 a 14:00'],
                $b->id => ['jornada' => '08:00 a 16:00', 'comida' => '15:00 a 16:00'], // solo cambia la comida
                $c->id => ['jornada' => '14:00 a 22:00', 'comida' => '13:00 a 14:00'],
            ],
            'motivo' => 'Cobertura por una incapacidad',
        ])->assertSessionHasNoErrors();

        $solicitudes = HorarioSolicitud::query()->orderBy('id')->get();
        $this->assertCount(3, $solicitudes);
        $this->assertCount(1, $solicitudes->pluck('lote')->unique());
        $this->assertNotNull($solicitudes->first()->lote);
        $this->assertSame(['pendiente', 'cambio_turno', $this->morelos->id], [$solicitudes[0]->estado, $solicitudes[0]->tipo, $solicitudes[0]->estacion_id]);
        $this->assertSame('15:00 a 16:00', $solicitudes[1]->nueva_comida);
        $this->assertSame('Cobertura por una incapacidad', $solicitudes[2]->motivo);

        $this->assertSame('08:00 a 16:00', $a->fresh()->jornada, 'Proponer no modifica la tabla.');
        $this->assertSame('oficial', $a->fresh()->origen);
        Queue::assertPushed(EnviarAvisoTickets::class, 1);

        // Las celdas con solicitud pendiente se ven como propuestas, no editables.
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07', 'editar' => 1]))->assertOk()->assertSee('Cambio pedido')->assertSee('Propuesto');
        $this->assertSame($periodo->id, $solicitudes->first()->periodo_id);
    }

    public function test_estacion_edit_requires_a_reason_a_real_change_and_only_its_own_turns(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $propio = $this->turno('241232', '2026-12-08');
        $ajeno = $this->turno('240428', '2026-12-08');

        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$propio->id => ['jornada' => 'DESCANSO']]])->assertInvalid('motivo');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$propio->id => ['jornada' => 'DESCANSO']], 'motivo' => 'corto'])->assertInvalid('motivo');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$propio->id => ['jornada' => '08:00 a 16:00', 'comida' => '13:00 a 14:00']], 'motivo' => 'Sin ningún cambio real'])->assertInvalid('turnos');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$ajeno->id => ['jornada' => 'DESCANSO']], 'motivo' => 'Turno de otra estación'])->assertForbidden();
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$propio->id => ['jornada' => 'DESCANSO'], 999999 => ['jornada' => 'X']], 'motivo' => 'Un turno que no existe'])->assertInvalid('turnos');

        $this->assertSame(0, HorarioSolicitud::query()->count());
    }

    public function test_a_duplicate_pending_cell_rolls_back_the_whole_batch(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $a = $this->turno('241232', '2026-12-08');
        $b = $this->turno('241232', '2026-12-09');

        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO']], 'motivo' => 'Primer envío de cambios'])->assertSessionHasNoErrors();
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$b->id => ['jornada' => 'VACACIONES'], $a->id => ['jornada' => 'COMISIÓN']], 'motivo' => 'Segundo envío con celda repetida'])->assertInvalid('turno');

        $this->assertSame(1, HorarioSolicitud::query()->count(), 'Nada del segundo envío se guardó.');
    }

    public function test_zone_authorizes_a_whole_lote_which_changes_the_table_and_notifies_once(): void
    {
        $this->activarAvisos();
        $this->publicado();
        $this->usuario('Estación', $this->morelos, 'morelos@ejemplo.test');
        $estacion = $this->usuario('Estación', $this->morelos);
        $this->actingAsTwoFactorVerified($estacion);
        $a = $this->turno('241232', '2026-12-08');
        $b = $this->turno('241300', '2026-12-08');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO', 'comida' => ''], $b->id => ['jornada' => 'VACACIONES', 'comida' => '']], 'motivo' => 'Rol de descansos del mes'])->assertSessionHasNoErrors();
        $lote = HorarioSolicitud::query()->first()->lote;

        $this->post(route('horarios.lotes.autorizar', $lote))->assertForbidden();

        Queue::fake();
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->get(route('horarios.solicitudes.index'))->assertOk()->assertSee('Autorizar todo el envío')->assertSee('2 cambios pendientes');
        $this->post(route('horarios.lotes.autorizar', $lote), ['comentario' => 'Ok'])->assertSessionHasNoErrors();

        $this->assertSame(['DESCANSO', 'ajustado', '08:00 a 16:00'], [$a->fresh()->jornada, $a->fresh()->origen, $a->fresh()->jornada_oficial]);
        $this->assertSame('VACACIONES', $b->fresh()->jornada);
        $this->assertSame(2, HorarioSolicitud::query()->where('estado', 'autorizada')->where('aplicada', true)->count());
        Queue::assertPushed(EnviarAvisoTickets::class, 1);

        $this->post(route('horarios.lotes.autorizar', $lote))->assertInvalid('solicitud');
    }

    public function test_authorizing_a_lote_is_all_or_nothing(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $a = $this->turno('241232', '2026-12-08');
        $b = $this->turno('241300', '2026-12-08');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO'], $b->id => ['jornada' => 'VACACIONES']], 'motivo' => 'Cambios que no se aplican a medias']);
        $lote = HorarioSolicitud::query()->first()->lote;

        $b->update(['jornada' => '06:00 a 14:00']); // cambió después de la solicitud

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.lotes.autorizar', $lote))->assertInvalid('solicitud');

        $this->assertSame('08:00 a 16:00', $a->fresh()->jornada);
        $this->assertSame(2, HorarioSolicitud::query()->where('estado', 'pendiente')->count());
    }

    public function test_zone_rejects_a_lote_with_a_comment_and_the_table_stays(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $a = $this->turno('241232', '2026-12-08');
        $this->post(route('horarios.cambios.proponer'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO']], 'motivo' => 'Quiero descansar ese día']);
        $lote = HorarioSolicitud::query()->first()->lote;

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.lotes.rechazar', $lote), [])->assertInvalid('comentario');
        $this->post(route('horarios.lotes.rechazar', $lote), ['comentario' => 'No hay cobertura'])->assertSessionHasNoErrors();

        $this->assertSame(['rechazada', 'No hay cobertura'], [HorarioSolicitud::query()->sole()->estado, HorarioSolicitud::query()->sole()->comentario_resolucion]);
        $this->assertSame('08:00 a 16:00', $a->fresh()->jornada);
    }

    public function test_zone_edits_directly_and_it_is_marked_and_notified_to_the_estacion(): void
    {
        $this->activarAvisos();
        $this->publicado();
        $this->usuario('Estación', $this->morelos, 'morelos@ejemplo.test');
        $edificio = $this->usuario('Edificio');
        $this->actingAsTwoFactorVerified($edificio);
        $a = $this->turno('241232', '2026-12-08');
        $b = $this->turno('240428', '2026-12-08');

        $this->put(route('horarios.turnos.guardar'), [
            'turnos' => [
                $a->id => ['jornada' => 'DESCANSO', 'comida' => '', 'antes' => HorarioEdicionService::huella($a)],
                $b->id => ['jornada' => 'VACACIONES', 'comida' => '', 'antes' => HorarioEdicionService::huella($b)],
            ],
            'nota' => 'Ajuste por oficio',
        ])->assertSessionHasNoErrors();

        $a->refresh();
        $this->assertSame(['DESCANSO', null, 'ajustado', '08:00 a 16:00', $edificio->id, 'Ajuste por oficio'], [$a->jornada, $a->comida, $a->origen, $a->jornada_oficial, $a->editado_por, $a->nota]);
        $this->assertNotNull($a->editado_at);
        Queue::assertPushed(EnviarAvisoTickets::class, 1); // solo la estación con cuenta vinculada (Tulum no tiene)
    }

    public function test_zone_direct_save_does_not_overwrite_a_cell_changed_meanwhile(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $a = $this->turno('241232', '2026-12-08');
        $huellaVieja = HorarioEdicionService::huella($a);
        $a->update(['jornada' => '06:00 a 14:00']); // otra persona la cambió

        $this->put(route('horarios.turnos.guardar'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO', 'comida' => '', 'antes' => $huellaVieja]]])
            ->assertSessionHasErrors('conflictos');

        $this->assertSame('06:00 a 14:00', $a->fresh()->jornada);
    }

    public function test_estacion_cannot_save_directly(): void
    {
        $this->publicado();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $a = $this->turno('241232', '2026-12-08');

        $this->put(route('horarios.turnos.guardar'), ['turnos' => [$a->id => ['jornada' => 'DESCANSO', 'antes' => HorarioEdicionService::huella($a)]]])->assertForbidden();
        $this->assertSame('08:00 a 16:00', $a->fresh()->jornada);
    }

    public function test_editing_a_draft_does_not_mark_turns_as_adjusted(): void
    {
        $this->empleado($this->morelos, '241232', 'Ana Morelos');
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.crear.store'), ['anio' => 2026, 'mes' => 12, 'estaciones' => [$this->morelos->id], 'modo' => 'blanco']);
        $turno = HorarioTurno::query()->whereDate('fecha', '2026-12-08')->sole();

        $this->put(route('horarios.turnos.guardar'), ['turnos' => [$turno->id => ['jornada' => '09:00 a 17:00', 'comida' => '13:00 a 14:00', 'antes' => HorarioEdicionService::huella($turno)]]])->assertSessionHasNoErrors();

        $this->assertSame(['09:00 a 17:00', 'oficial', null], [$turno->fresh()->jornada, $turno->fresh()->origen, $turno->fresh()->jornada_oficial]);
    }

    public function test_the_week_view_shows_the_edit_controls_for_each_role(): void
    {
        $this->publicado();
        $params = ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07'];

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('horarios.index', $params))->assertOk()->assertSee('Editar horario')->assertSee('Intercambiar fecha')->assertDontSee('Pedir cambio')->assertDontSee('Rellenar por patrón');
        $this->get(route('horarios.index', $params + ['editar' => 1]))->assertOk()->assertSee('Enviar a Edificio')->assertSee('Motivo de los cambios')->assertDontSee('Rellenar por patrón')->assertDontSee('Carla Tulum');

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->get(route('horarios.index', $params + ['editar' => 1]))->assertOk()->assertSee('Guardar cambios')->assertSee('Rellenar por patrón')->assertSee('Agregar persona')->assertSee('Carla Tulum');
    }

    // === C. Consolidado ============================================================================

    public function test_the_consolidado_lists_direct_edits_and_authorized_changes_in_the_changes_sheet(): void
    {
        $this->publicado();
        $edificio = $this->usuario('Edificio');
        $this->actingAsTwoFactorVerified($edificio);
        $a = $this->turno('241232', '2026-12-08');
        $this->put(route('horarios.turnos.guardar'), ['turnos' => [$a->id => ['jornada' => 'COMISIÓN', 'comida' => '', 'antes' => HorarioEdicionService::huella($a)]], 'nota' => 'Comisión a Mérida']);

        $this->artisan('horarios:consolidado-semanal', ['--semana' => '2026-12-07'])->assertSuccessful();

        $consolidado = HorarioConsolidado::query()->sole();
        $this->assertSame([21, 1], [$consolidado->turnos, $consolidado->cambios]);
        $cambios = IOFactory::load(Storage::disk('local')->path($consolidado->ruta))->getSheetByName('CAMBIOS')->toArray(null, true, false, false);
        $this->assertCount(2, $cambios);
        $this->assertSame(['Ana Morelos', '08:00 a 16:00', 'COMISIÓN', $edificio->name, 'Comisión a Mérida'], [$cambios[1][2], $cambios[1][4], $cambios[1][5], $cambios[1][8], $cambios[1][10]]);
    }
}
