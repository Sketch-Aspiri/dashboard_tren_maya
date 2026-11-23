<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TurnoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\Asistencia\HojaGoogleCliente;
use App\Services\Asistencia\SincronizadorAsistenciaSheets;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Sincronización de la asistencia del Dashboard al Google Sheet, con un cliente de Google falso (nunca sale a internet).
 */
class AsistenciaSheetsSincronizacionTest extends TestCase
{
    use RefreshDatabase;

    private FakeHojaGoogle $google;

    private CarbonImmutable $lunes;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'asistencia_sheets.activo' => true,
            'asistencia_sheets.spreadsheet_id' => 'hoja-de-prueba',
            'asistencia_sheets.hoja_fija' => '',
            'asistencia_sheets.estacion_descansa_finde' => 'Edificio Zonal',
        ]);
        Cache::flush();

        // Lunes 23-nov-2026, 07:30 Cancún (ventana de la mañana). Serial de Sheets: 46349.
        $this->lunes = CarbonImmutable::create(2026, 11, 23, 7, 30, 0, 'America/Cancun');
        $this->google = new FakeHojaGoogle([
            ['No.', 'Nombre', 'Puesto', 'Area', 46348, 46349],
            ['101', 'Ana Uno', 'x', 'x'],
            ['102', 'Beto Dos', 'x', 'x'],
        ]);
        $this->app->instance(HojaGoogleCliente::class, $this->google);
    }

    private function persona(Estacion $estacion, string $numero, string $nombre, ?EstatusAsistencia $estatus, TurnoAsistencia $turno = TurnoAsistencia::Primer, ?CarbonImmutable $fecha = null): Empleado
    {
        $empleado = Empleado::factory()->create([
            'estacion_id' => $estacion->id, 'estatus' => EmpleadoEstatus::Activo->value,
            'no_empleado' => $numero, 'nombre_completo' => $nombre,
        ]);

        if ($estatus !== null) {
            RegistroDiario::factory()->create([
                'empleado_id' => $empleado->id, 'estacion_id' => $estacion->id,
                'fecha' => ($fecha ?? $this->lunes)->toDateString(), 'estatus' => $estatus->value, 'turno' => $turno->value,
            ]);
        }

        return $empleado;
    }

    private function sincronizar(array $opciones = []): array
    {
        return app(SincronizadorAsistenciaSheets::class)->ejecutar(
            $opciones['ahora'] ?? $this->lunes, $opciones['modo'] ?? null, $opciones['limite'] ?? false, $opciones['simular'] ?? false,
        );
    }

    public function test_apagado_no_hace_nada(): void
    {
        config(['asistencia_sheets.activo' => false]);

        $this->assertSame('omitido', $this->sincronizar()['estado']);
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_fuera_de_ventana_no_hace_nada(): void
    {
        $r = $this->sincronizar(['ahora' => $this->lunes->setTime(11, 0)]);

        $this->assertSame('omitido', $r['estado']);
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_espera_si_una_estacion_no_termino_de_capturar(): void
    {
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);
        $this->persona($e, '102', 'Beto Dos', null);

        $r = $this->sincronizar();

        $this->assertSame('esperando', $r['estado']);
        $this->assertSame(['Tulum'], $r['estaciones_pendientes']);
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_a_la_hora_limite_escribe_lo_que_haya_y_reporta_lo_que_falta(): void
    {
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);
        $this->persona($e, '102', 'Beto Dos', null);

        $r = $this->sincronizar(['limite' => true]);

        $this->assertSame('escrito', $r['estado']);
        $this->assertSame([[['range' => "'Noviembre'!F2", 'values' => [['PRESENTE']]]]], $this->google->escrituras);
        $this->assertSame('Beto Dos', $r['reporte']['incompletos'][0]['nombre']);
    }

    public function test_por_la_manana_solo_escribe_primer_turno_y_por_la_tarde_todos(): void
    {
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente, TurnoAsistencia::Primer);
        $this->persona($e, '102', 'Beto Dos', EstatusAsistencia::Descanso, TurnoAsistencia::Segundo);

        $this->sincronizar();
        $this->assertCount(1, $this->google->escrituras[0]);

        $this->sincronizar(['ahora' => $this->lunes->setTime(13, 0)]);
        $this->assertCount(2, $this->google->escrituras[1]);
    }

    public function test_una_vez_completo_no_vuelve_a_escribir_ese_turno(): void
    {
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);

        $this->assertSame('escrito', $this->sincronizar()['estado']);
        $this->assertSame('omitido', $this->sincronizar()['estado']);
        $this->assertCount(1, $this->google->escrituras);
    }

    public function test_dry_run_no_escribe_pero_muestra_las_celdas(): void
    {
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);

        $r = $this->sincronizar(['simular' => true]);

        $this->assertSame('simulado', $r['estado']);
        $this->assertCount(1, $r['celdas']);
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_el_fin_de_semana_la_estacion_que_descansa_sale_en_descanso_sin_capturar(): void
    {
        $sabado = CarbonImmutable::create(2026, 11, 28, 7, 30, 0, 'America/Cancun'); // serial 46354
        $this->google->hoja[0] = ['No.', 'Nombre', 'Puesto', 'Area', 46353, 46354];
        $zonal = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este']);
        $this->persona($zonal, '101', 'Ana Uno', null);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($tulum, '102', 'Beto Dos', EstatusAsistencia::Presente, TurnoAsistencia::Primer, $sabado);

        $r = $this->sincronizar(['ahora' => $sabado]);

        $this->assertSame('escrito', $r['estado']);
        $valores = collect($this->google->escrituras[0])->mapWithKeys(fn ($c) => [$c['range'] => $c['values'][0][0]]);
        $this->assertSame('DESCANSO', $valores["'Noviembre'!F2"]);
        $this->assertSame('PRESENTE', $valores["'Noviembre'!F3"]);
    }

    public function test_sin_columna_de_hoy_no_escribe_y_es_error(): void
    {
        $this->google->hoja[0] = ['No.', 'Nombre', 'Puesto', 'Area', 46000, 46001];
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);

        $r = $this->sincronizar();

        $this->assertSame('error', $r['estado']);
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_el_comando_dry_run_imprime_el_resultado(): void
    {
        $this->travelTo($this->lunes);
        $e = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->persona($e, '101', 'Ana Uno', EstatusAsistencia::Presente);

        $this->artisan('asistencia:sincronizar-sheets', ['--dry-run' => true])
            ->expectsOutputToContain('Estado: simulado')
            ->assertSuccessful();
        $this->assertSame([], $this->google->escrituras);
    }

    public function test_el_comando_rechaza_un_modo_invalido(): void
    {
        $this->artisan('asistencia:sincronizar-sheets', ['--modo' => 'noche'])->assertFailed();
    }
}

/** Cliente de Google en memoria: devuelve la hoja y guarda lo que se le pide escribir. */
class FakeHojaGoogle implements HojaGoogleCliente
{
    /** @var list<list<array{range: string, values: list<list<string>>}>> */
    public array $escrituras = [];

    /** @param  list<list<mixed>>  $hoja */
    public function __construct(public array $hoja) {}

    public function leer(string $spreadsheetId, string $rango): array
    {
        return $this->hoja;
    }

    public function escribir(string $spreadsheetId, array $celdas): void
    {
        $this->escrituras[] = $celdas;
    }
}
