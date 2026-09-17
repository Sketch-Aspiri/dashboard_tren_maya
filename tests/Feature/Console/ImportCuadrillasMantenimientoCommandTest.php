<?php

namespace Tests\Feature\Console;

use App\Enums\EmpleadoEstatus;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-cuadrillas-mantenimiento. Uses a tiny
 * SYNTHETIC .xlsx fixture built at test time with PhpSpreadsheet — never
 * the real Jefe de Zona roster file, which contains real employee PII and
 * must never be referenced from test code (see CLAUDE.md and
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportCuadrillasMantenimientoCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET_NAME = 'Cuadrillas de Mantenimiento ';

    private ?string $fixturePath = null;

    protected function tearDown(): void
    {
        if ($this->fixturePath !== null && file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /**
     * Expands a sparse "column index => value" map into a dense 0-24 array
     * (25 columns, matching the highest mapped column the command uses)
     * suitable for Worksheet::fromArray().
     *
     * @param  array<int, mixed>  $columns
     * @return list<mixed>
     */
    private function columnsToRow(array $columns): array
    {
        $row = array_fill(0, 25, null);

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

        // Row 1: main header. Row 2: "Contacto de Emergencia" sub-headers
        // (Nombre / Contacto (Telefono)) — mirrors the real file's merged
        // X1:Y1 header. Data starts at row 3.
        $sheet->fromArray($this->columnsToRow([
            0 => 'No.', 1 => 'No. Estación', 2 => 'Plaza actual', 3 => 'No. Empleado', 4 => 'FOTO',
            5 => 'Cargo', 6 => 'Nivel de Plaza Eventual.', 7 => 'Nombre completo', 8 => 'Ultimo grado de estudios',
            9 => 'Oficio', 10 => 'Fecha de ingreso', 11 => 'No. Telefonico', 12 => 'Correo',
            13 => 'Tipo de sangre', 14 => 'Alergias', 15 => 'Edad en número', 16 => 'Fecha de Nacimiento',
            17 => 'Lugar nacimiento', 18 => 'Estado Civil', 19 => 'CURP', 20 => 'RFC', 21 => 'NSS',
            22 => 'Domicilio', 23 => 'Contacto de Emergencia',
        ]), null, 'A1');
        $sheet->fromArray($this->columnsToRow([23 => 'Nombre', 24 => 'Contacto (Telefono)']), null, 'A2');

        // Row 3 (data): normal eventual employee, with the two separate
        // "Contacto de Emergencia" sub-columns populated independently
        // (the real sheet is NOT a single combined column — see
        // ImportCuadrillasMantenimientoCommand's docblock).
        $sheet->fromArray($this->columnsToRow([
            1 => '24', 2 => 'Playa del Carmen', 3 => ' 30001 ',
            5 => 'Auxiliar de Servicios y Mantenimiento C1', 6 => '4', 7 => 'Juan Pérez Uc',
            8 => 'Secundaria', 9 => 'Electricista',
            10 => '2021-06-01',
            11 => '9981234567', 12 => 'juan.perez@example.test', 13 => 'O+', 14 => 'Ninguna',
            16 => '1995-02-10', 17 => 'Chetumal, Quintana Roo', 18 => 'Soltero',
            19 => 'PEUJ950210HQRRXX01', 20 => 'PEUJ950210AB1', 21 => '12345678901',
            22 => 'Calle 1 #100', 23 => 'Ana María Chi Canul', 24 => '9831134918',
        ]), null, 'A3');

        // Row 4 (data): vacant position.
        $sheet->fromArray($this->columnsToRow([
            1 => '28', 2 => 'Felipe Carrillo Puerto', 3 => 'VACANTE',
            5 => 'Auxiliar de mantenimiento', 6 => '11',
        ]), null, 'A4');

        // Row 5 (data): fully empty row, must be skipped.
        $sheet->setCellValue('A5', '');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cuadrillas-mantenimiento-fixture-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->fixturePath = $path;

        return $path;
    }

    public function test_import_marks_created_rows_as_eventual_and_activo(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 2);

        $empleado = Empleado::query()->where('no_empleado', '30001')->firstOrFail();
        $this->assertSame(TipoPlaza::Eventual, $empleado->tipo_plaza);
        $this->assertSame(EmpleadoEstatus::Activo, $empleado->estatus);
        $this->assertSame('Juan Pérez Uc', $empleado->nombre_completo);
        $this->assertSame('Electricista', $empleado->titulo);
        $this->assertSame('2021-06-01', $empleado->fecha_ingreso->toDateString());
        $this->assertSame('1995-02-10', $empleado->fecha_nacimiento->toDateString());
    }

    public function test_import_leaves_cedula_and_desempeno_null(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $empleado = Empleado::query()->where('no_empleado', '30001')->firstOrFail();

        $this->assertNull($empleado->cedula);
        $this->assertNull($empleado->desempeno);
    }

    /**
     * The real sheet splits "Contacto de Emergencia" into two real
     * sub-columns (Nombre / Contacto (Telefono)) under one merged header,
     * confirmed by inspecting the source file directly — both are mapped
     * and stored independently.
     */
    public function test_import_stores_emergency_contact_name_and_phone_separately(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $empleado = Empleado::query()->where('no_empleado', '30001')->firstOrFail();

        $this->assertSame('Ana María Chi Canul', $empleado->contacto_emergencia_nombre);
        $this->assertSame('9831134918', $empleado->contacto_emergencia_telefono);
    }

    public function test_import_creates_vacante_record_and_skips_empty_row(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $vacante = Empleado::query()->where('estatus', EmpleadoEstatus::Vacante->value)->firstOrFail();

        $this->assertNull($vacante->no_empleado);
        $this->assertSame(TipoPlaza::Eventual, $vacante->tipo_plaza);
        $this->assertSame('Felipe Carrillo Puerto', $vacante->plaza_actual);
    }

    public function test_rerunning_the_import_is_idempotent(): void
    {
        $path = $this->buildFixture();

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 2);

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::SUCCESS);

        $this->assertDatabaseCount('empleados', 2);
    }

    public function test_import_fails_clearly_when_the_sheet_is_missing(): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->setTitle('Otra Hoja');
        $spreadsheet->getActiveSheet()->fromArray(['a', 'b'], null, 'A1');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cuadrillas-mantenimiento-missing-sheet-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->fixturePath = $path;

        $this->artisan('app:import-cuadrillas-mantenimiento', ['path' => $path])
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('empleados', 0);
    }
}
