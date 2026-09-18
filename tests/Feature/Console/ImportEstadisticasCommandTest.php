<?php

namespace Tests\Feature\Console;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-estadisticas. Uses a tiny SYNTHETIC
 * .xlsx fixture built at test time with PhpSpreadsheet — never the real
 * Jefe de Zona estadísticas file (see CLAUDE.md and
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportEstadisticasCommandTest extends TestCase
{
    use RefreshDatabase;

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null && file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /**
     * Builds a "FLUJO Y BOLETOS ENE" sheet with:
     *  - a "Puerto Morelos" block with 3 days of data, one day with both
     *    cells blank (must be skipped), and a TOTAL row closing the block;
     *  - a "NICOLAS BRAVO ENERO" block (must resolve to the "Kohunlich"
     *    estación via the alias map) with 1 day of data.
     */
    private function buildFixture(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('FLUJO Y BOLETOS ENE');

        $rows = [
            ['Puerto Morelos', null, null],
            ['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS'],
            [1, 100, 90],
            [2, null, null],
            [3, 120, 110],
            ['TOTAL PUERTO MORELOS', 220, 200],
            ['NICOLAS BRAVO ENERO', null, null],
            ['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS'],
            [1, 50, 45],
            ['TOTAL NICOLAS BRAVO', 50, 45],
        ];

        $sheet->fromArray($rows, null, 'A1');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'estadisticas-fixture-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->fixturePath = $path;

        return $path;
    }

    private function seedEstaciones(): void
    {
        Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'is_operativa' => true, 'orden' => 1]);
        Estacion::factory()->create(['nombre' => 'Kohunlich', 'is_operativa' => true, 'orden' => 2]);
    }

    public function test_import_creates_registros_for_known_and_aliased_stations(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-estadisticas', ['path' => $path, '--anio' => 2026])
            ->assertExitCode(Command::SUCCESS);

        $puertoMorelos = Estacion::where('nombre', 'Puerto Morelos')->firstOrFail();
        $kohunlich = Estacion::where('nombre', 'Kohunlich')->firstOrFail();

        // 3 day-rows created: 2 for Puerto Morelos (día 2 is blank and
        // skipped), 1 for Kohunlich via the "Nicolás Bravo" alias.
        $this->assertDatabaseCount('estadisticas_diarias', 3);

        $dia1 = EstadisticaDiaria::query()->where('estacion_id', $puertoMorelos->id)->whereDate('fecha', '2026-01-01')->firstOrFail();
        $this->assertSame(100, $dia1->abordan);
        $this->assertSame(90, $dia1->boletos_vendidos);

        $dia3 = EstadisticaDiaria::query()->where('estacion_id', $puertoMorelos->id)->whereDate('fecha', '2026-01-03')->firstOrFail();
        $this->assertSame(120, $dia3->abordan);
        $this->assertSame(110, $dia3->boletos_vendidos);

        $this->assertNull(
            EstadisticaDiaria::query()->where('estacion_id', $puertoMorelos->id)->whereDate('fecha', '2026-01-02')->first(),
        );

        $diaKohunlich = EstadisticaDiaria::query()->where('estacion_id', $kohunlich->id)->whereDate('fecha', '2026-01-01')->firstOrFail();
        $this->assertSame(50, $diaKohunlich->abordan);
        $this->assertSame(45, $diaKohunlich->boletos_vendidos);
    }

    public function test_import_infers_anio_from_the_filename_when_no_option_is_given(): void
    {
        $this->seedEstaciones();
        $sourcePath = $this->buildFixture();

        $renamedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ESTADISTICA 2027.xlsx';
        rename($sourcePath, $renamedPath);
        $this->fixturePath = $renamedPath;

        $this->artisan('app:import-estadisticas', ['path' => $renamedPath])
            ->assertExitCode(Command::SUCCESS);

        $this->assertNotNull(EstadisticaDiaria::query()->whereDate('fecha', '2027-01-01')->first());
    }

    public function test_import_fails_clearly_when_the_anio_cannot_be_resolved(): void
    {
        $this->seedEstaciones();
        $sourcePath = $this->buildFixture();

        // Deliberately digit-free filename (unlike uniqid(), whose hex
        // output can easily contain 4 consecutive decimal digits by
        // chance, making a "no year in the filename" test flaky).
        $suffix = substr(str_shuffle('abcdefghijklmnopqrstuvwxyz'), 0, 10);
        $renamedPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'estadisticas-sin-anio-'.$suffix.'.xlsx';
        rename($sourcePath, $renamedPath);
        $this->fixturePath = $renamedPath;

        $this->artisan('app:import-estadisticas', ['path' => $renamedPath])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('estadisticas_diarias', 0);
    }

    public function test_import_reports_an_unrecognized_station_block_without_failing_the_whole_import(): void
    {
        $this->seedEstaciones();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('FLUJO Y BOLETOS ENE');

        $sheet->fromArray([
            ['Estación Fantasma', null, null],
            ['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS'],
            [1, 10, 10],
            ['TOTAL', 10, 10],
            ['Puerto Morelos', null, null],
            ['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS'],
            [1, 20, 15],
            ['TOTAL PUERTO MORELOS', 20, 15],
        ], null, 'A1');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'estadisticas-unrecognized-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->fixturePath = $path;

        $this->artisan('app:import-estadisticas', ['path' => $path, '--anio' => 2026])
            ->assertExitCode(Command::SUCCESS);

        // Only Puerto Morelos' day is imported — the unrecognized
        // "Estación Fantasma" block is skipped entirely, never crashes the
        // whole import.
        $this->assertDatabaseCount('estadisticas_diarias', 1);
        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-01-01')->first();
        $this->assertNotNull($registro);
        $this->assertSame(20, $registro->abordan);
    }

    public function test_rerunning_the_import_is_idempotent(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-estadisticas', ['path' => $path, '--anio' => 2026])
            ->assertExitCode(Command::SUCCESS);
        $this->assertDatabaseCount('estadisticas_diarias', 3);

        $this->artisan('app:import-estadisticas', ['path' => $path, '--anio' => 2026])
            ->assertExitCode(Command::SUCCESS);
        $this->assertDatabaseCount('estadisticas_diarias', 3);
    }

    public function test_import_fails_clearly_when_the_file_cannot_be_read(): void
    {
        // A path that simply doesn't exist reliably makes IOFactory::load()
        // throw (unlike plain garbage text with a .xlsx extension, which
        // PhpSpreadsheet's reader auto-detection may still parse as a
        // trivial CSV instead of failing).
        $this->seedEstaciones();

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'estadisticas-does-not-exist-'.uniqid().'.xlsx';

        $this->artisan('app:import-estadisticas', ['path' => $path, '--anio' => 2026])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('estadisticas_diarias', 0);
    }
}
