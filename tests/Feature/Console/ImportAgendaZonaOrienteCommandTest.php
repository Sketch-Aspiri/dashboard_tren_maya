<?php

namespace Tests\Feature\Console;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-agenda-zona-oriente. Uses a tiny
 * SYNTHETIC .xlsx fixture built at test time with PhpSpreadsheet — never
 * the real Jefe de Zona roster file, which contains real employee PII and
 * must never be referenced from test code (see CLAUDE.md and
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportAgendaZonaOrienteCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET_NAME = 'Base de Datos Zona Oriente';

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null && file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /**
     * Expands a sparse "column index => value" map into a dense 0-29
     * array (30 columns, matching the highest mapped column used by the
     * import command) suitable for Worksheet::fromArray().
     *
     * @param  array<int, ?string>  $columns
     * @return list<?string>
     */
    private function columnsToRow(array $columns): array
    {
        $row = array_fill(0, 30, null);

        foreach ($columns as $index => $value) {
            $row[$index] = $value;
        }

        return $row;
    }

    private function buildFixture(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        // Header row (row 1) — content is illustrative only, the command
        // maps by column index, not by header text. Explicit integer keys
        // (matching the 0-based column mapping) are used for every row
        // below to avoid off-by-one column-count mistakes.
        $headers = [
            0 => 'idx0', 1 => 'No. Estación', 2 => 'Plaza actual', 3 => 'No. Empleado', 4 => 'idx4',
            5 => 'Nombre', 6 => 'Puesto', 7 => 'Nivel de plaza', 8 => 'Último grado de estudios', 9 => 'Título',
            10 => 'Cédula', 11 => 'Fecha de ingreso', 12 => 'idx12', 13 => 'idx13', 14 => 'Teléfono',
            15 => 'Correo', 16 => 'Tipo de sangre', 17 => 'Alergias', 18 => 'idx18', 19 => 'Fecha de nacimiento',
            20 => 'Lugar de nacimiento', 21 => 'Estado civil', 22 => 'CURP', 23 => 'RFC', 24 => 'NSS',
            25 => 'Domicilio', 26 => 'Contacto emergencia nombre', 27 => 'Contacto emergencia teléfono', 28 => 'Desempeño', 29 => 'idx29',
        ];
        $sheet->fromArray($this->columnsToRow($headers), null, 'A1');

        // Row 2 (data index 1): normal "activo" employee.
        $sheet->fromArray($this->columnsToRow([
            1 => 'EST-01', 2 => 'PZ-001', 3 => ' 10001 ',
            5 => '  Juana   Pérez  López ', 6 => 'Auxiliar de vía', 7 => 'M11', 8 => 'Preparatoria',
            11 => '2020-03-15',
            14 => '9990001111', 15 => 'juana.perez@example.test', 16 => 'O+',
            19 => '1990-05-20', 20 => 'Ciudad de México', 21 => 'Soltera',
            22 => 'PELJ900520MDFRPN01', 23 => 'PELJ900520AB1', 24 => '12345678901',
            25 => 'Calle Falsa 123', 26 => 'Contacto Uno', 27 => '9990002222', 28 => 'Desempeño sobresaliente',
        ]), null, 'A2');

        // Row 3 (data index 2): vacant position.
        $sheet->fromArray($this->columnsToRow([
            1 => 'EST-02', 2 => 'PZ-002', 3 => 'VACANTE',
            6 => 'Auxiliar de mantenimiento', 7 => '11', 8 => 'Secundaria',
        ]), null, 'A3');

        // Row 4 (data index 3): row with an Excel error string in correo,
        // plus dates written as free-text Spanish strings (the real
        // roster's actual format for ~54 date cells) instead of real
        // Excel date cells — regression coverage for the day/month/year
        // and Spanish-month-name parsing.
        $sheet->fromArray($this->columnsToRow([
            1 => 'EST-03', 2 => 'PZ-003', 3 => '10003',
            5 => 'Carlos Ruiz', 6 => 'Auxiliar administrativo', 7 => 'M22', 8 => 'Licenciatura', 9 => 'Ingeniero',
            11 => 'Domingo, 01 de septiembre de 2024',
            14 => '9990003333', 15 => '#VALUE!', 16 => 'A+',
            19 => '12/11/90',
            20 => 'Mérida', 21 => 'Casado',
            22 => 'RUCC850110HYNXXX02', 23 => 'RUCC850110AB2', 24 => '11122233344',
            25 => 'Avenida Siempre Viva 742', 26 => 'Contacto Dos', 27 => '9990004444',
        ]), null, 'A4');

        // Row 5 (data index 4): fully empty row, must be skipped. Written
        // as an explicit empty string (not omitted) so PhpSpreadsheet
        // registers the row as "used" and getHighestDataRow() includes it
        // — fromArray() skips cells that equal its $nullValue entirely.
        $sheet->setCellValue('A5', '');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agenda-zona-oriente-fixture-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->fixturePath = $path;

        return $path;
    }

    /**
     * Mirrors the real roster: a visible activo row and a row hidden by
     * the spreadsheet author (e.g. a stale/superseded entry) — hidden rows
     * must be excluded from the import regardless of their content, since
     * they're invisible when the Jefe de Zona opens the file normally.
     */
    private function buildFixtureWithHiddenRow(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(self::SHEET_NAME);

        $sheet->fromArray($this->columnsToRow([1 => 'No. Estación', 3 => 'No. Empleado', 5 => 'Nombre']), null, 'A1');

        $sheet->fromArray($this->columnsToRow([
            1 => 'EST-01', 2 => 'PZ-001', 3 => '20001', 5 => 'Visible Empleado', 6 => 'Puesto visible',
        ]), null, 'A2');

        $sheet->fromArray($this->columnsToRow([
            1 => 'EST-02', 2 => 'PZ-002', 3 => '20002', 5 => 'Oculto Empleado', 6 => 'Puesto oculto',
        ]), null, 'A3');
        $sheet->getRowDimension(3)->setVisible(false);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agenda-zona-oriente-hidden-row-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->fixturePath = $path;

        return $path;
    }

    public function test_import_excludes_rows_hidden_in_the_source_spreadsheet(): void
    {
        $path = $this->buildFixtureWithHiddenRow();

        $this->artisan('app:import-agenda-zona-oriente', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 1);
        $this->assertDatabaseHas('empleados', ['no_empleado' => '20001']);
        $this->assertDatabaseMissing('empleados', ['no_empleado' => '20002']);
    }

    public function test_import_creates_activo_and_vacante_records_and_skips_empty_rows(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-agenda-zona-oriente', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 3);

        $activo = Empleado::query()->where('no_empleado', '10001')->firstOrFail();
        $this->assertSame(EmpleadoEstatus::Activo, $activo->estatus);
        $this->assertSame('Juana Pérez López', $activo->nombre_completo);
        $this->assertSame('EST-01', $activo->estacion_codigo);
        $this->assertSame('2020-03-15', $activo->fecha_ingreso->toDateString());
        $this->assertSame('1990-05-20', $activo->fecha_nacimiento->toDateString());
        $this->assertSame('Desempeño sobresaliente', $activo->desempeno);

        $vacante = Empleado::query()->where('estatus', EmpleadoEstatus::Vacante->value)->firstOrFail();
        $this->assertNull($vacante->no_empleado);
        $this->assertSame('EST-02', $vacante->estacion_codigo);
        $this->assertSame('Auxiliar de mantenimiento', $vacante->puesto);

        // orden_origen must track the sheet row number so the listing can
        // default-sort to match the Excel order (row 2 -> 2, row 3 -> 3, ...).
        $this->assertSame(2, $activo->orden_origen);
        $this->assertSame(3, $vacante->orden_origen);

        $withJunkField = Empleado::query()->where('no_empleado', '10003')->firstOrFail();
        $this->assertNull($withJunkField->correo);
        $this->assertSame('Carlos Ruiz', $withJunkField->nombre_completo);
        // "Domingo, 01 de septiembre de 2024" (Spanish long-form, day-of-week prefix).
        $this->assertSame('2024-09-01', $withJunkField->fecha_ingreso->toDateString());
        // "12/11/90" must be read as day/month/year (Mexican convention), not
        // the American month/day/year Carbon::parse() would default to.
        $this->assertSame('1990-11-12', $withJunkField->fecha_nacimiento->toDateString());
        $this->assertSame(4, $withJunkField->orden_origen);
    }

    public function test_rerunning_the_import_is_idempotent(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-agenda-zona-oriente', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 3);

        $this->artisan('app:import-agenda-zona-oriente', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 3);
    }

    public function test_import_fails_clearly_when_the_sheet_is_missing(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Otra Hoja');
        $spreadsheet->getActiveSheet()->fromArray(['a', 'b'], null, 'A1');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'agenda-zona-oriente-missing-sheet-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->fixturePath = $path;

        $this->artisan('app:import-agenda-zona-oriente', ['path' => $path])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('empleados', 0);
    }
}
