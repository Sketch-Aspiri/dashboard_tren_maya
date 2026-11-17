<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoTickets;
use App\Models\Estacion;
use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Models\User;
use App\Services\Horarios\HorarioEdicionService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use DateTime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Módulo Horarios: Edificio sube el oficial, cada estación ve solo el suyo y pide cambios,
 * la Zona autoriza (la tabla cambia) y cada lunes se genera el consolidado.
 * Datos ficticios con la misma forma del Excel real (una hoja por estación).
 */
class HorariosTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const ENCABEZADO = ['No', 'No. Empleado', 'Nombre completo ', 'Denominación del Puesto', 'Estación ', 'Fecha', 'Jornada Laboral', 'Horario de comida (1 Hr.)', 'Semana '];

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

    private function dia(string $fecha): float
    {
        return Date::dateTimeToExcel(new DateTime($fecha));
    }

    /**
     * Filas (empleado, nombre, estación, fecha, jornada, comida) de una hoja con el encabezado del oficial.
     *
     * @param  list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string}>  $filas
     * @return list<list<mixed>>
     */
    private function hoja(array $filas): array
    {
        $datos = [self::ENCABEZADO];

        foreach ($filas as $i => [$emp, $nombre, $estacion, $fecha, $jornada, $comida]) {
            $datos[] = [$i + 1, $emp, $nombre, 'Gerencia de Estación', $estacion, $this->dia($fecha), $jornada, $comida, 'Semana 1'];
        }

        return $datos;
    }

    /**
     * @param  array<string, list<list<mixed>>>  $hojas
     */
    private function excel(array $hojas, string $nombre = 'DGTZO_ROL DE HORARIOS DICIEMBRE.xlsx'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $this->excelBytes($hojas));
    }

    /**
     * @param  array<string, list<list<mixed>>>  $hojas
     */
    private function excelBytes(array $hojas): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        foreach ($hojas as $titulo => $filas) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($titulo);

            foreach ($filas as $i => $fila) {
                foreach ($fila as $j => $valor) {
                    $hoja->setCellValue([$j + 1, $i + 1], $valor);
                }
            }
        }

        $ruta = tempnam(sys_get_temp_dir(), 'hor');
        (new Xlsx($libro))->save($ruta);
        $bytes = (string) file_get_contents($ruta);
        unlink($ruta);

        return $bytes;
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function oficial(): array
    {
        return [
            'EJEMPLO' => $this->hoja([[1, 'Persona Ejemplo', 'Mérida Teya', '2026-12-07', '07:00 a 15:00', '13:00 a 14:00']]),
            '23_Puerto Morelos.' => $this->hoja([
                [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-07', '08:00 a 16:00', '10:00 a 11:00'],
                [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-08', 'DESCANSO', 'DESCANSO'],
                [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-09', '08:00 a 16:00', '10:00 a 11:00'],
                [241300, 'Beto Morelos', '23_Puerto Morelos.', '2026-12-07', '14:00 a 22:00', '17:00 a 18:00'],
            ]),
            '25_Tulum.' => $this->hoja([
                [240428, 'Carla Tulum', '25_Tulum.', '2026-12-07', '09:30 a 17:30', '14:00 a 15:00'],
                [240428, 'Carla Tulum', '25_Tulum.', '2026-12-08', '09:30 a 17:30', '14:00 a 15:00'],
            ]),
            'Hoja2' => [[null, 'Horarios', 'Semana', 'Horario de comida']],
        ];
    }

    private function publicar(?array $hojas = null, string $nombre = 'DGTZO_ROL DE HORARIOS DICIEMBRE.xlsx'): HorarioPeriodo
    {
        $edificio = $this->usuario('Edificio');
        $this->actingAsTwoFactorVerified($edificio);

        $respuesta = $this->post(route('horarios.importar.store'), ['archivo' => $this->excel($hojas ?? $this->oficial(), $nombre)]);
        $token = basename((string) $respuesta->headers->get('Location'));
        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertSessionHasNoErrors();

        return HorarioPeriodo::query()->where('estado', HorarioPeriodo::VIGENTE)->firstOrFail();
    }

    private function turno(string $empleado, string $fecha): HorarioTurno
    {
        return HorarioTurno::query()->where('no_empleado', $empleado)->whereDate('fecha', $fecha)->firstOrFail();
    }

    private function pedirCambio(HorarioTurno $turno, array $datos = []): void
    {
        $this->post(route('horarios.solicitudes.store', $turno), $datos + ['tipo' => 'cambio_turno', 'nueva_jornada' => '14:00 a 22:00', 'motivo' => 'Cubre una incapacidad del compañero'])
            ->assertSessionHasNoErrors();
    }

    // --- Autenticación / autorización ---------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('horarios.index'))->assertRedirect(route('login'));
        $this->get(route('horarios.importar.create'))->assertRedirect(route('login'));
        $this->get(route('horarios.solicitudes.index'))->assertRedirect(route('login'));
    }

    public function test_estacion_cannot_upload_or_see_consolidados(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $this->get(route('horarios.importar.create'))->assertForbidden();
        $this->post(route('horarios.importar.store'), ['archivo' => $this->excel($this->oficial())])->assertForbidden();
        $this->get(route('horarios.consolidados.index'))->assertForbidden();
        $this->post(route('horarios.consolidados.generar'))->assertForbidden();
    }

    // --- Importación ----------------------------------------------------------------------------------

    public function test_preview_writes_nothing_and_apply_publishes_and_notifies_each_estacion(): void
    {
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
        Queue::fake();
        $this->usuario('Estación', $this->morelos, 'morelos@ejemplo.test');
        $this->usuario('Estación', $this->tulum, 'tulum@ejemplo.test');

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $respuesta = $this->post(route('horarios.importar.store'), ['archivo' => $this->excel($this->oficial())])->assertSessionHasNoErrors();
        $token = basename((string) $respuesta->headers->get('Location'));

        $this->assertSame(0, HorarioTurno::query()->count());
        $this->get(route('horarios.importar.vista', $token))->assertOk()->assertSee('Puerto Morelos')->assertSee('Tulum');

        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertRedirect();

        $periodo = HorarioPeriodo::query()->sole();
        $this->assertSame([2026, 12, 1, 'vigente'], [$periodo->anio, $periodo->mes, $periodo->version, $periodo->estado]);
        $this->assertSame(6, HorarioTurno::query()->count(), 'La hoja EJEMPLO y la hoja sin encabezados no se importan.');
        $this->assertSame($this->morelos->id, $this->turno('241232', '2026-12-07')->estacion_id);
        $this->assertSame('DESCANSO', $this->turno('241232', '2026-12-08')->jornada);
        Queue::assertPushed(EnviarAvisoTickets::class, 2);
    }

    public function test_rows_without_a_recognized_estacion_are_kept_but_only_zone_sees_them(): void
    {
        $this->publicar(['Misteriosa' => $this->hoja([[999, 'Sin Estación', 'Mérida Teya', '2026-12-07', '08:00 a 16:00', '13:00 a 14:00']])]);

        $this->assertNull($this->turno('999', '2026-12-07')->estacion_id);

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertDontSee('Sin Estación');
    }

    public function test_row_without_employee_number_takes_it_from_the_same_person_in_that_sheet(): void
    {
        $hoja = $this->hoja([
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-07', '08:00 a 16:00', '10:00 a 11:00'],
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-08', '08:00 a 16:00', '10:00 a 11:00'],
        ]);
        $hoja[2][1] = null; // el 8 de diciembre quedó sin No. Empleado en el Excel
        $hoja[] = [3, null, 'Persona Desconocida', 'Gerencia', '23_Puerto Morelos.', $this->dia('2026-12-09'), '08:00 a 16:00', '', 'Semana 1'];

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $respuesta = $this->post(route('horarios.importar.store'), ['archivo' => $this->excel(['23_Puerto Morelos.' => $hoja])]);
        $token = basename((string) $respuesta->headers->get('Location'));
        $this->get(route('horarios.importar.vista', $token))->assertOk()->assertSee('se completó con el de la misma persona')->assertSee('no tiene número de empleado');

        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertSessionHasNoErrors();
        $this->assertSame('241232', $this->turno('241232', '2026-12-08')->no_empleado);
        $this->assertSame(2, HorarioTurno::query()->count(), 'La fila sin forma de recuperar el número se omite.');
    }

    public function test_invalid_files_are_rejected(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));

        $this->post(route('horarios.importar.store'), ['archivo' => UploadedFile::fake()->create('horario.pdf', 10, 'application/pdf')])->assertInvalid('archivo');
        $this->post(route('horarios.importar.store'), ['archivo' => $this->excel(['Datos' => [['a', 'b'], [1, 2]]])])->assertInvalid('archivo');
        $this->post(route('horarios.importar.aplicar'), ['token' => 'no-es-uuid'])->assertInvalid('token');
        $this->post(route('horarios.importar.aplicar'), ['token' => '11111111-1111-4111-8111-111111111111'])->assertInvalid('archivo');
        $this->assertSame(0, HorarioPeriodo::query()->count());
    }

    public function test_preview_token_belongs_to_the_uploader(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $token = basename((string) $this->post(route('horarios.importar.store'), ['archivo' => $this->excel($this->oficial())])->headers->get('Location'));

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->get(route('horarios.importar.vista', $token))->assertNotFound();
        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertInvalid('archivo');
    }

    // --- Alcance por estación ------------------------------------------------------------------

    public function test_estacion_sees_only_its_own_schedule_and_cannot_request_changes_on_another(): void
    {
        $this->publicar();
        $ajeno = $this->turno('240428', '2026-12-07');

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));

        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))
            ->assertOk()->assertSee('Ana Morelos')->assertSee('Beto Morelos')->assertDontSee('Carla Tulum');
        // Pedir ver otra estación por parámetro no amplía el alcance.
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07', 'estacion' => $this->tulum->id]))
            ->assertOk()->assertDontSee('Carla Tulum');

        $this->post(route('horarios.solicitudes.store', $ajeno), ['tipo' => 'cambio_turno', 'nueva_jornada' => 'DESCANSO', 'motivo' => 'Intento de otra estación'])->assertForbidden();
        $this->assertSame(0, HorarioSolicitud::query()->count());
    }

    public function test_estacion_without_estacion_assigned_sees_nothing(): void
    {
        $this->publicar();
        $sinEstacion = $this->usuario('Estación');
        $this->actingAsTwoFactorVerified($sinEstacion);

        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertDontSee('Ana Morelos')->assertDontSee('Carla Tulum');
    }

    public function test_zone_sees_all_and_can_filter_by_estacion(): void
    {
        $this->publicar();

        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07']))->assertOk()->assertSee('Ana Morelos')->assertSee('Carla Tulum');
        $this->get(route('horarios.index', ['anio' => 2026, 'mes' => 12, 'desde' => '2026-12-07', 'estacion' => $this->tulum->id]))->assertOk()->assertSee('Carla Tulum')->assertDontSee('Ana Morelos');
    }

    // --- Solicitudes ------------------------------------------------------------------------------------

    public function test_estacion_requests_and_zone_authorizes_which_changes_the_table(): void
    {
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
        ]);
        $this->publicar();
        $estacionUser = $this->usuario('Estación', $this->morelos, 'morelos@ejemplo.test');
        $edificio = $this->usuario('Edificio', null, 'edificio@ejemplo.test');
        $turno = $this->turno('241232', '2026-12-07');

        Queue::fake();
        $this->actingAsTwoFactorVerified($estacionUser);
        $this->pedirCambio($turno, ['nueva_jornada' => '14:00 a 22:00', 'nueva_comida' => '17:00 a 18:00']);
        Queue::assertPushed(EnviarAvisoTickets::class);

        $solicitud = HorarioSolicitud::query()->sole();
        $this->assertSame(['pendiente', '08:00 a 16:00', $this->morelos->id], [$solicitud->estado, $solicitud->jornada_origen, $solicitud->estacion_id]);
        $this->assertSame('08:00 a 16:00', $turno->fresh()->jornada, 'Pedir no modifica la tabla.');

        // Un segundo pedido sobre el mismo turno se rechaza, y la estación no puede autorizar.
        $this->post(route('horarios.solicitudes.store', $turno), ['tipo' => 'cambio_turno', 'nueva_jornada' => 'DESCANSO', 'motivo' => 'Otro intento sobre el mismo turno'])->assertInvalid('turno');
        $this->post(route('horarios.solicitudes.autorizar', $solicitud))->assertForbidden();

        $this->actingAsTwoFactorVerified($edificio);
        $this->post(route('horarios.solicitudes.autorizar', $solicitud), ['comentario' => 'Autorizado'])->assertSessionHasNoErrors();

        $turno->refresh();
        $this->assertSame(['14:00 a 22:00', '17:00 a 18:00', 'ajustado', '08:00 a 16:00'], [$turno->jornada, $turno->comida, $turno->origen, $turno->jornada_oficial]);
        $this->assertSame(['autorizada', true, $edificio->id], [$solicitud->fresh()->estado, $solicitud->fresh()->aplicada, $solicitud->fresh()->resuelta_por]);

        // Ya resuelta: no se puede resolver otra vez.
        $this->post(route('horarios.solicitudes.rechazar', $solicitud), ['comentario' => 'tarde'])->assertForbidden();
    }

    public function test_date_change_swaps_the_two_days(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $lunes = $this->turno('241232', '2026-12-07');
        $martes = $this->turno('241232', '2026-12-08');

        $this->pedirCambio($lunes, ['tipo' => 'cambio_fecha', 'nueva_fecha' => '2026-12-08', 'nueva_jornada' => null]);
        $this->post(route('horarios.solicitudes.store', $this->turno('241232', '2026-12-09')), ['tipo' => 'cambio_fecha', 'nueva_fecha' => '2026-12-25', 'motivo' => 'Fecha que no existe en el horario'])->assertInvalid('nueva_fecha');

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.autorizar', HorarioSolicitud::query()->sole()))->assertSessionHasNoErrors();

        $this->assertSame(['DESCANSO', 'DESCANSO'], [$lunes->fresh()->jornada, $lunes->fresh()->comida]);
        $this->assertSame(['08:00 a 16:00', '10:00 a 11:00'], [$martes->fresh()->jornada, $martes->fresh()->comida]);
        $this->assertSame('08:00 a 16:00', $lunes->fresh()->jornada_oficial);
        $this->assertSame('DESCANSO', $martes->fresh()->jornada_oficial);
    }

    public function test_reject_requires_a_comment_and_cancel_is_limited_to_the_owner_estacion(): void
    {
        $this->publicar();
        $estacionUser = $this->usuario('Estación', $this->morelos);
        $this->actingAsTwoFactorVerified($estacionUser);
        $this->pedirCambio($this->turno('241232', '2026-12-07'));
        $this->pedirCambio($this->turno('241300', '2026-12-07'), ['nueva_jornada' => 'DESCANSO']);
        [$primera, $segunda] = HorarioSolicitud::query()->orderBy('id')->get()->all();

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->tulum));
        $this->post(route('horarios.solicitudes.cancelar', $primera))->assertForbidden();

        $this->actingAsTwoFactorVerified($estacionUser);
        $this->post(route('horarios.solicitudes.cancelar', $primera))->assertSessionHasNoErrors();
        $this->assertSame('cancelada', $primera->fresh()->estado);

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.rechazar', $segunda), [])->assertInvalid('comentario');
        $this->post(route('horarios.solicitudes.rechazar', $segunda), ['comentario' => 'No hay cobertura'])->assertSessionHasNoErrors();

        $this->assertSame(['rechazada', 'No hay cobertura'], [$segunda->fresh()->estado, $segunda->fresh()->comentario_resolucion]);
        $this->assertSame('14:00 a 22:00', $this->turno('241300', '2026-12-07')->jornada, 'Rechazar no modifica la tabla.');
    }

    public function test_authorizing_fails_if_the_turn_changed_since_the_request(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $turno = $this->turno('241232', '2026-12-07');
        $this->pedirCambio($turno);

        $turno->update(['jornada' => '06:00 a 14:00']);

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.autorizar', HorarioSolicitud::query()->sole()))->assertInvalid('solicitud');
        $this->assertSame('06:00 a 14:00', $turno->fresh()->jornada);
    }

    public function test_solicitudes_list_is_scoped_by_estacion(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->pedirCambio($this->turno('241232', '2026-12-07'));

        $this->get(route('horarios.solicitudes.index'))->assertOk()->assertSee('Ana Morelos');

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->tulum));
        $this->get(route('horarios.solicitudes.index'))->assertOk()->assertDontSee('Ana Morelos');
    }

    // --- Resubir el oficial --------------------------------------------------------------------

    public function test_resubmitting_the_month_keeps_authorized_changes_and_relinks_pending_ones(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->pedirCambio($this->turno('241232', '2026-12-07'), ['nueva_jornada' => '14:00 a 22:00']);
        $this->pedirCambio($this->turno('241300', '2026-12-07'), ['nueva_jornada' => 'DESCANSO']);
        [$autorizada, $pendiente] = HorarioSolicitud::query()->orderBy('id')->get()->all();

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.autorizar', $autorizada));

        // Corrección del oficial: cambia la jornada base de Ana y se agrega un turno.
        $hojas = $this->oficial();
        $hojas['23_Puerto Morelos.'] = $this->hoja([
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-07', '09:00 a 17:00', '11:00 a 12:00'],
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-08', 'DESCANSO', 'DESCANSO'],
            [241300, 'Beto Morelos', '23_Puerto Morelos.', '2026-12-07', '14:00 a 22:00', '17:00 a 18:00'],
            [241300, 'Beto Morelos', '23_Puerto Morelos.', '2026-12-08', '14:00 a 22:00', '17:00 a 18:00'],
        ]);
        $nuevo = $this->publicar($hojas, 'DGTZO_ROL DE HORARIOS DICIEMBRE v2.xlsx');

        $this->assertSame(2, $nuevo->version);
        $this->assertSame(1, HorarioPeriodo::query()->where('estado', HorarioPeriodo::REEMPLAZADO)->count());

        $ana = HorarioTurno::query()->where('periodo_id', $nuevo->id)->where('no_empleado', '241232')->whereDate('fecha', '2026-12-07')->sole();
        $this->assertSame(['14:00 a 22:00', 'ajustado', '09:00 a 17:00'], [$ana->jornada, $ana->origen, $ana->jornada_oficial], 'El cambio autorizado se vuelve a aplicar sobre el oficial nuevo.');

        $this->assertSame($nuevo->id, $autorizada->fresh()->periodo_id);
        $this->assertTrue($autorizada->fresh()->aplicada);
        $this->assertSame([$nuevo->id, 'pendiente'], [$pendiente->fresh()->periodo_id, $pendiente->fresh()->estado]);
        $this->assertSame($ana->id, $autorizada->fresh()->turno_id);
    }

    public function test_resubmitting_flags_authorized_changes_that_no_longer_fit(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->pedirCambio($this->turno('241232', '2026-12-09'), ['nueva_jornada' => 'VACACIONES']);
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.autorizar', HorarioSolicitud::query()->sole()));

        // En el oficial nuevo Ana ya no tiene turno el 9.
        $hojas = $this->oficial();
        $hojas['23_Puerto Morelos.'] = $this->hoja([[241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-07', '08:00 a 16:00', '10:00 a 11:00']]);
        $this->publicar($hojas, 'otro.xlsx');

        $this->assertFalse(HorarioSolicitud::query()->sole()->aplicada);
        $this->get(route('horarios.solicitudes.index', ['estado' => 'autorizada']))->assertOk()->assertSee('ya no se pudo aplicar');
    }

    public function test_resubmitting_the_month_keeps_the_zone_direct_edits_and_reports_the_ones_that_no_longer_fit(): void
    {
        $this->publicar();
        $edificio = $this->usuario('Edificio');
        $this->actingAsTwoFactorVerified($edificio);
        $ana = $this->turno('241232', '2026-12-07');
        $beto = $this->turno('241300', '2026-12-07');
        $this->put(route('horarios.turnos.guardar'), ['turnos' => [
            $ana->id => ['jornada' => 'COMISIÓN', 'comida' => '', 'antes' => HorarioEdicionService::huella($ana)],
            $beto->id => ['jornada' => 'VACACIONES', 'comida' => '', 'antes' => HorarioEdicionService::huella($beto)],
        ], 'nota' => 'Oficio 123'])->assertSessionHasNoErrors();

        // Corrección del oficial: Ana cambia su base; Beto ya no aparece en esa estación.
        $hojas = $this->oficial();
        $hojas['23_Puerto Morelos.'] = $this->hoja([
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-07', '09:00 a 17:00', '11:00 a 12:00'],
            [241232, 'Ana Morelos', '23_Puerto Morelos.', '2026-12-08', 'DESCANSO', 'DESCANSO'],
        ]);
        $this->actingAsTwoFactorVerified($edificio);
        $respuesta = $this->post(route('horarios.importar.store'), ['archivo' => $this->excel($hojas, 'v2.xlsx')]);
        $token = basename((string) $respuesta->headers->get('Location'));
        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertSessionHas('status', fn ($m) => str_contains($m, '1 ajuste'));

        $nuevo = HorarioPeriodo::query()->where('estado', HorarioPeriodo::VIGENTE)->sole();
        $nueva = HorarioTurno::query()->where('periodo_id', $nuevo->id)->where('no_empleado', '241232')->whereDate('fecha', '2026-12-07')->sole();
        $this->assertSame(['COMISIÓN', 'ajustado', '09:00 a 17:00', $edificio->id, 'Oficio 123'], [$nueva->jornada, $nueva->origen, $nueva->jornada_oficial, $nueva->editado_por, $nueva->nota]);
        $this->assertSame(0, HorarioTurno::query()->where('periodo_id', $nuevo->id)->where('no_empleado', '241300')->count());
    }

    public function test_identical_file_cannot_be_published_twice(): void
    {
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $bytes = $this->excelBytes($this->oficial());

        $token = basename((string) $this->post(route('horarios.importar.store'), ['archivo' => UploadedFile::fake()->createWithContent('a.xlsx', $bytes)])->headers->get('Location'));
        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertSessionHasNoErrors();

        $token = basename((string) $this->post(route('horarios.importar.store'), ['archivo' => UploadedFile::fake()->createWithContent('a.xlsx', $bytes)])->headers->get('Location'));
        $this->post(route('horarios.importar.aplicar'), ['token' => $token])->assertInvalid('archivo');

        $this->assertSame(1, HorarioPeriodo::query()->count());
    }

    // --- Consolidado semanal --------------------------------------------------------------------

    public function test_weekly_command_generates_the_workbook_with_changes_applied(): void
    {
        $this->publicar();
        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->pedirCambio($this->turno('241232', '2026-12-07'), ['nueva_jornada' => 'COMISIÓN']);
        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->post(route('horarios.solicitudes.autorizar', HorarioSolicitud::query()->sole()));

        $this->artisan('horarios:consolidado-semanal', ['--semana' => '2026-12-10'])->assertSuccessful();

        $consolidado = HorarioConsolidado::query()->sole();
        $this->assertSame('2026-12-07', $consolidado->semana_inicio->toDateString());
        $this->assertSame([6, 1], [$consolidado->turnos, $consolidado->cambios]);
        Storage::disk('local')->assertExists($consolidado->ruta);

        $libro = IOFactory::load(Storage::disk('local')->path($consolidado->ruta));
        $this->assertContains('GENERAL', $libro->getSheetNames());
        $this->assertContains('CAMBIOS', $libro->getSheetNames());
        $general = $libro->getSheetByName('GENERAL')->toArray(null, true, false, false);
        $this->assertCount(7, $general, 'Encabezado más 6 turnos de la semana.');
        $this->assertContains('COMISIÓN', array_column($general, 6));
        $this->assertContains('Ajustado', array_column($general, 9));

        // Regenerar la misma semana reemplaza, no duplica.
        $this->artisan('horarios:consolidado-semanal', ['--semana' => '2026-12-07'])->assertSuccessful();
        $this->assertSame(1, HorarioConsolidado::query()->count());
    }

    public function test_weekly_command_is_scheduled_on_mondays_in_cancun(): void
    {
        $evento = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'horarios:consolidado-semanal'));

        $this->assertNotNull($evento);
        $this->assertSame('0 7 * * 1', $evento->expression);
        $this->assertSame('America/Cancun', (string) $evento->timezone);
    }

    public function test_consolidado_download_is_limited_to_zone_and_logged(): void
    {
        $this->publicar();
        $this->artisan('horarios:consolidado-semanal', ['--semana' => '2026-12-07']);
        $consolidado = HorarioConsolidado::query()->sole();

        $this->actingAsTwoFactorVerified($this->usuario('Estación', $this->morelos));
        $this->get(route('horarios.consolidados.descargar', $consolidado))->assertForbidden();

        $this->actingAsTwoFactorVerified($this->usuario('Edificio'));
        $this->get(route('horarios.consolidados.index'))->assertOk()->assertSee('07/12/2026');
        $this->get(route('horarios.consolidados.descargar', $consolidado))->assertOk()->assertDownload($consolidado->nombre);

        $this->post(route('horarios.consolidados.generar'), ['semana' => CarbonImmutable::parse('2026-12-14')->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame(2, HorarioConsolidado::query()->count());
    }
}
