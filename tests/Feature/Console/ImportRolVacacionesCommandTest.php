<?php

namespace Tests\Feature\Console;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\Vacacionista;
use App\Models\VacacionPeriodo;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-rol-vacaciones. Uses a tiny SYNTHETIC
 * .xlsx fixture built at test time with PhpSpreadsheet — never the real
 * ANEXO A de vacacionistas (see .claude/rules/testing.md "Dummy Data").
 */
class ImportRolVacacionesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            is_dir($path) ? rmdir($path) : @unlink($path);
        }

        parent::tearDown();
    }

    /**
     * Header on row 4 like the real sheet. Data:
     *  - 1001 (Puerto Morelos): real Excel dates in T1 (Mon 2-Feb to Fri
     *    6-Feb = 5 weekdays, the file says 9 on purpose) and T2 (Mon 4-May to
     *    Mon 11-May = 6 weekdays).
     *  - 1002 (NICOLAS BRAVO/KOHUNLICH): two periods in T4 as text cells
     *    split by a line break, day/month/year.
     *  - 1003: an estación that is not in the catalog.
     */
    private function buildFixture(string $fileName = 'ANEXO_0317_160226_VACACIONISTAS 2026.xlsx'): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray(['NO.', 'NO. EMPLEADO', 'Nombre Completo', 'Días otorgados'], null, 'C4');

        $this->fila($sheet, 5, ['1001', 'PERSONA UNO', 20, 'PUERTO MORELOS ', 'Gerente de Estación'], [
            1 => ['2026-02-02', '2026-02-06', 9],
            2 => ['2026-05-04', '2026-05-11', 6],
        ]);

        $this->fila($sheet, 6, ['1002', 'PERSONA DOS', 20, 'NICOLÁS BRAVO/KOHUNLICH', 'Taquillero'], [
            4 => ["05/10/2026\n17/11/2026", "09/10/2026 /\n23/11/2026", "5 /\n5"],
        ]);

        $this->fila($sheet, 7, ['1003', 'PERSONA TRES', 20, 'ESTACIÓN FANTASMA', 'Operativo'], [
            3 => ['2026-08-03', '2026-08-07', 5],
        ]);

        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rol-vac-'.uniqid();
        mkdir($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$fileName;
        (new Xlsx($spreadsheet))->save($path);

        array_push($this->cleanup, $path, $directory);

        return $path;
    }

    /**
     * @param  array{0: string, 1: string, 2: int, 3: string, 4: string}  $persona
     * @param  array<int, array{0: string, 1: string, 2: int|string}>  $bloques  trimestre => [inicio, término, días]
     */
    private function fila(Worksheet $sheet, int $row, array $persona, array $bloques): void
    {
        $sheet->setCellValue("D{$row}", $persona[0]);
        $sheet->setCellValue("E{$row}", $persona[1]);
        $sheet->setCellValue("F{$row}", $persona[2]);
        $sheet->setCellValue("T{$row}", $persona[3]);
        $sheet->setCellValue("V{$row}", $persona[4]);

        $primeraColumna = [1 => 'G', 2 => 'J', 3 => 'M', 4 => 'P'];

        foreach ($bloques as $trimestre => [$inicio, $termino, $dias]) {
            $columna = $primeraColumna[$trimestre];
            $siguientes = [chr(ord($columna) + 1), chr(ord($columna) + 2)];

            foreach ([$columna => $inicio, $siguientes[0] => $termino] as $letra => $valor) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) === 1) {
                    $sheet->setCellValue("{$letra}{$row}", ExcelDate::PHPToExcel(strtotime($valor)));
                    $sheet->getStyle("{$letra}{$row}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_DDMMYYYY);
                } else {
                    $sheet->setCellValueExplicit("{$letra}{$row}", $valor, DataType::TYPE_STRING);
                }
            }

            $sheet->setCellValue("{$siguientes[1]}{$row}", $dias);
        }
    }

    private function seedEstaciones(): void
    {
        Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'orden' => 1]);
        Estacion::factory()->create(['nombre' => 'Kohunlich', 'orden' => 2]);
    }

    public function test_import_creates_vacacionistas_with_periods_and_resolves_estaciones(): void
    {
        $this->seedEstaciones();
        $empleado = Empleado::factory()->create(['no_empleado' => '1001']);

        $this->artisan('app:import-rol-vacaciones', ['path' => $this->buildFixture()])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, Vacacionista::count());

        $uno = Vacacionista::where('no_empleado', '1001')->firstOrFail();
        $this->assertSame(2026, $uno->anio);
        $this->assertSame('PERSONA UNO', $uno->nombre_completo);
        $this->assertSame(20, $uno->dias_otorgados);
        $this->assertSame('Puerto Morelos', $uno->estacion->nombre);
        $this->assertSame($empleado->id, $uno->empleado_id);
        $this->assertSame(2, $uno->periodos()->count());

        $dos = Vacacionista::where('no_empleado', '1002')->firstOrFail();
        $this->assertSame('Kohunlich', $dos->estacion->nombre);
        $this->assertNull($dos->empleado_id);

        $tres = Vacacionista::where('no_empleado', '1003')->firstOrFail();
        $this->assertNull($tres->estacion_id);
    }

    public function test_import_stores_weekday_count_instead_of_the_excel_number(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-rol-vacaciones', ['path' => $this->buildFixture()])
            ->expectsOutputToContain('1001 T1: Excel 9 / calculado 5')
            ->assertExitCode(Command::SUCCESS);

        $uno = Vacacionista::where('no_empleado', '1001')->firstOrFail();

        $this->assertSame([5, 6], $uno->periodos->pluck('dias_solicitados')->all());
        $this->assertSame(11, $uno->total_dias);
    }

    public function test_import_splits_two_periods_from_one_text_cell(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-rol-vacaciones', ['path' => $this->buildFixture()])
            ->assertExitCode(Command::SUCCESS);

        $periodos = Vacacionista::where('no_empleado', '1002')->firstOrFail()->periodos;

        $this->assertCount(2, $periodos);
        $this->assertSame([4, 4], $periodos->pluck('trimestre')->all());
        $this->assertSame(['2026-10-05', '2026-11-17'], $periodos->map(fn ($p) => $p->fecha_inicio->toDateString())->all());
        $this->assertSame(['2026-10-09', '2026-11-23'], $periodos->map(fn ($p) => $p->fecha_termino->toDateString())->all());
        $this->assertSame([5, 5], $periodos->pluck('dias_solicitados')->all());
    }

    public function test_reimport_updates_in_place_without_duplicating_rows(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-rol-vacaciones', ['path' => $path])->assertExitCode(Command::SUCCESS);
        $this->artisan('app:import-rol-vacaciones', ['path' => $path])->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, Vacacionista::count());
        $this->assertSame(5, VacacionPeriodo::count());
    }

    public function test_year_option_overrides_the_year_inferred_from_the_file_name(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-rol-vacaciones', ['path' => $this->buildFixture(), '--anio' => 2027])
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, Vacacionista::where('anio', 2027)->count());
    }

    public function test_import_fails_when_the_year_cannot_be_determined(): void
    {
        $this->artisan('app:import-rol-vacaciones', ['path' => $this->buildFixture('vacacionistas.xlsx')])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Vacacionista::count());
    }

    public function test_import_fails_on_a_file_without_the_expected_header(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['otra', 'cosa']], null, 'A1');
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rol-vac-mal-'.uniqid().' 2026.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->cleanup[] = $path;

        $this->artisan('app:import-rol-vacaciones', ['path' => $path])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, Vacacionista::count());
    }
}
