<?php

namespace Tests\Feature;

use App\Models\AfluenciaDiaria;
use App\Models\Estacion;
use App\Models\User;
use App\Services\Afluencia\AfluenciaImportador;
use App\Services\Afluencia\AfluenciaLibro;
use App\Services\Afluencia\AfluenciaService;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Flujo de pasajeros: captura diaria por estación, tablero de la Zona, Excel e importador. */
class AfluenciaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $tulum;

    private Estacion $kohunlich;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25', 'orden' => 8]);
        $this->kohunlich = Estacion::factory()->create(['nombre' => 'Kohunlich', 'codigo' => '31', 'orden' => 4]);
    }

    private function con(string $rol, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($rol);
        $this->actingAsTwoFactorVerified($user);

        return $user;
    }

    private function ayer(): CarbonImmutable
    {
        return CarbonImmutable::now('America/Cancun')->startOfDay();
    }

    /** @return array<string, mixed> */
    private function envio(array $dias, ?CarbonImmutable $en = null): array
    {
        $en ??= $this->ayer();

        return ['anio' => $en->year, 'mes' => $en->month, 'dias' => $dias];
    }

    public function test_station_captures_its_own_day_and_it_is_audited(): void
    {
        $user = $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $hoy = $this->ayer();

        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([$hoy->day => ['abordan' => '120', 'boletos' => '45']]))
            ->assertRedirect();

        $this->assertDatabaseHas('afluencia_diaria', ['estacion_id' => $this->tulum->id, 'fecha' => $hoy->toDateString().' 00:00:00', 'abordan' => 120, 'boletos' => 45, 'capturado_por' => $user->id]);
        $this->assertTrue(Activity::query()->where('log_name', 'afluencia')->where('event', 'created')->exists());
    }

    public function test_station_cannot_capture_or_view_another_station(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->put(route('afluencia.guardar', $this->kohunlich), $this->envio([1 => ['abordan' => 5, 'boletos' => 5]]))->assertForbidden();
        $this->get(route('afluencia.estacion', $this->kohunlich))->assertForbidden();
        $this->assertDatabaseCount('afluencia_diaria', 0);
    }

    public function test_station_is_redirected_to_its_own_capture_and_cannot_download(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('afluencia.index'))->assertRedirect();
        $this->get(route('afluencia.descargar'))->assertForbidden();
    }

    public function test_zone_sees_the_board_corrects_any_station_and_downloads_excel(): void
    {
        $this->con('Jefe de Zona');
        $hoy = $this->ayer();
        $this->put(route('afluencia.guardar', $this->kohunlich), $this->envio([$hoy->day => ['abordan' => 7, 'boletos' => 3]]))->assertRedirect();

        $this->get(route('afluencia.index'))->assertOk()->assertSee('Kohunlich')->assertSee('Tulum');
        $this->get(route('afluencia.descargar', ['anio' => $hoy->year]))->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=FLUJO_PASAJEROS_'.$hoy->year.'.xlsx');
    }

    public function test_guest_is_sent_to_login_and_personal_role_is_forbidden(): void
    {
        $this->get(route('afluencia.index'))->assertRedirect(route('login'));

        $this->con('Personal');
        $this->get(route('afluencia.index'))->assertForbidden();
    }

    public function test_validation_rejects_negative_text_future_and_nonexistent_days(): void
    {
        $this->con('Jefe de Zona');
        $hoy = $this->ayer();

        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([1 => ['abordan' => -1, 'boletos' => 'x']]))
            ->assertInvalid(['dias.1.abordan', 'dias.1.boletos']);
        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([32 => ['abordan' => 1]]))->assertInvalid();
        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([1 => ['abordan' => 100000]]))->assertInvalid(['dias.1.abordan']);

        $manana = $hoy->addDay();
        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([$manana->day => ['abordan' => 5]], $manana))->assertInvalid();
        $this->assertDatabaseCount('afluencia_diaria', 0);
    }

    public function test_empty_day_deletes_the_record_and_blank_is_not_zero(): void
    {
        $this->con('Jefe de Zona');
        $hoy = $this->ayer();
        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([$hoy->day => ['abordan' => 0, 'boletos' => '']]));

        $fila = AfluenciaDiaria::query()->first();
        $this->assertSame(0, $fila->abordan);
        $this->assertNull($fila->boletos);

        $this->put(route('afluencia.guardar', $this->tulum), $this->envio([$hoy->day => ['abordan' => '', 'boletos' => '']]));
        $this->assertDatabaseCount('afluencia_diaria', 0);
    }

    public function test_mass_assignment_ignores_unexpected_fields(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);
        $hoy = $this->ayer();
        $datos = $this->envio([$hoy->day => ['abordan' => 9, 'boletos' => 4, 'estacion_id' => $this->kohunlich->id]]);
        $datos['estacion_id'] = $this->kohunlich->id;

        $this->put(route('afluencia.guardar', $this->tulum), $datos)->assertRedirect();

        $this->assertSame($this->tulum->id, AfluenciaDiaria::query()->firstOrFail()->estacion_id);
    }

    public function test_board_totals_and_missing_days(): void
    {
        $hoy = $this->ayer();
        AfluenciaDiaria::query()->create(['estacion_id' => $this->tulum->id, 'fecha' => $hoy->toDateString(), 'abordan' => 10, 'boletos' => 4]);

        $fila = app(AfluenciaService::class)->tablero($hoy->year, $hoy->month)->firstWhere(fn ($f) => $f['estacion']->id === $this->tulum->id);

        $this->assertSame(10, $fila['abordan']);
        $this->assertSame(4, $fila['boletos']);
        $this->assertSame(1, $fila['capturados']);
        $this->assertSame($hoy->day - 1, $fila['faltan']);
    }

    public function test_board_does_not_count_today_as_missing(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'America/Cancun'));
        foreach (['2026-11-01', '2026-11-02'] as $fecha) {
            AfluenciaDiaria::query()->create(['estacion_id' => $this->tulum->id, 'fecha' => $fecha, 'abordan' => 10, 'boletos' => 4]);
        }
        // Capturar hoy no compensa un dia vencido que falte.
        AfluenciaDiaria::query()->create(['estacion_id' => $this->kohunlich->id, 'fecha' => '2026-11-03', 'abordan' => 1, 'boletos' => 1]);

        $tablero = app(AfluenciaService::class)->tablero(2026, 11)->keyBy(fn ($f) => $f['estacion']->id);

        $this->assertSame(0, $tablero[$this->tulum->id]['faltan']);
        $this->assertSame(2, $tablero[$this->kohunlich->id]['faltan']);
        $this->assertSame(1, $tablero[$this->kohunlich->id]['capturados']);
    }

    public function test_board_shows_a_dash_instead_of_zero_where_nothing_was_captured(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00', 'America/Cancun'));
        AfluenciaDiaria::query()->create(['estacion_id' => $this->tulum->id, 'fecha' => '2026-11-01', 'abordan' => 0, 'boletos' => null]);

        $tablero = app(AfluenciaService::class)->tablero(2026, 11)->keyBy(fn ($f) => $f['estacion']->id);

        $this->assertSame(0, $tablero[$this->tulum->id]['abordan']);
        $this->assertNull($tablero[$this->tulum->id]['boletos']);
        $this->assertNull($tablero[$this->kohunlich->id]['abordan']);

        $this->con('Jefe de Zona');
        $this->get(route('afluencia.index'))->assertOk()->assertSee('—')->assertSee('hasta ayer');
    }

    public function test_capture_form_warns_about_unsaved_changes(): void
    {
        $this->con('Estación', ['estacion_id' => $this->tulum->id]);

        $this->get(route('afluencia.estacion', $this->tulum))->assertOk()->assertSee('data-aviso-cambios', false);
    }

    public function test_importer_finds_blocks_by_header_maps_nicolas_bravo_and_is_idempotent(): void
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('FEBRERO');
        $hoja->fromArray([['PUERTO MORELOS ', null, null, null, 'TULUM']], null, 'B7');
        $hoja->fromArray([['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS', null, 'DÍA', 'ABORDAN', 'BOLETOS VENDIDOS']], null, 'B8');
        $hoja->fromArray([[1, 36, 41, null, 1, 249, 75], [2, null, null, null, 2, 240, null], ['TOTAL:', 36, 41]], null, 'B9');
        // Bloque desplazado (como en febrero): el nombre va una fila arriba de los encabezados.
        $hoja->fromArray([['NICOLAS BRAVO']], null, 'B40');
        $hoja->fromArray([['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS']], null, 'B41');
        $hoja->fromArray([[1, 83, 111], [29, 5, 5]], null, 'B42');
        $ruta = tempnam(sys_get_temp_dir(), 'afl').'.xlsx';
        (new Xlsx($libro))->save($ruta);

        $importador = app(AfluenciaImportador::class);
        $leido = $importador->leer($ruta, 2026);

        // Estación "PUERTO MORELOS" no existe en el catálogo de prueba: se informa y se omite.
        $this->assertSame(['PUERTO MORELOS (FEBRERO)'], $leido['sin_estacion']);
        $this->assertCount(3, $leido['dias']); // Tulum día 1, Tulum día 2 (solo abordan), Kohunlich día 1; el 29 no existe en 2026.
        $this->assertNotEmpty($leido['errores']);

        $this->assertSame(['creados' => 3, 'actualizados' => 0], $importador->aplicar($leido));
        $this->assertSame(['creados' => 0, 'actualizados' => 0], $importador->aplicar($leido));
        $this->assertSame(111, AfluenciaDiaria::query()->where('estacion_id', $this->kohunlich->id)->firstOrFail()->boletos);
        $this->assertNull(AfluenciaDiaria::query()->where('estacion_id', $this->tulum->id)->whereDay('fecha', 2)->firstOrFail()->boletos);

        @unlink($ruta);
    }

    public function test_excel_keeps_original_layout_with_total_formulas_and_blank_days(): void
    {
        AfluenciaDiaria::query()->create(['estacion_id' => $this->tulum->id, 'fecha' => '2026-03-02', 'abordan' => 50, 'boletos' => null]);
        $service = app(AfluenciaService::class);

        $libro = app(AfluenciaLibro::class)->anio(2026, $service->estaciones(), $service->anio(2026));
        $marzo = $libro->getSheetByName('MARZO');

        $this->assertSame(12, $libro->getSheetCount());
        $this->assertSame('TULUM', $marzo->getCell('J7')->getValue());
        $this->assertSame(50, $marzo->getCell('K10')->getValue()); // día 2 = fila 9 + 1
        $this->assertNull($marzo->getCell('L10')->getValue());
        $this->assertSame('=SUM(K9:K39)', $marzo->getCell('K40')->getValue());
    }
}
