<?php

namespace Tests\Feature\Console;

use App\Models\Elevador;
use App\Models\EscaleraElectrica;
use App\Models\Estacion;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-controles. Uses a tiny SYNTHETIC .xlsx
 * fixture built at test time — never the real ANEXO (see
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportControlesCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            @unlink($path);
        }

        parent::tearDown();
    }

    private function seedEstaciones(): void
    {
        foreach (['Puerto Morelos', 'Tulum', 'Tulum Aeropuerto'] as $orden => $nombre) {
            Estacion::factory()->create(['nombre' => $nombre, 'orden' => $orden + 1]);
        }
    }

    /**
     * Same shape as the real ANEXO: row 3 headers, blank "Estación" cells on
     * every row after the first for a given station (carried forward), and
     * one unparseable date (a lone "//") to prove it is skipped, not stored.
     */
    private function buildFixture(bool $conHojas = true): string
    {
        $spreadsheet = new Spreadsheet;

        $escaleras = $spreadsheet->getActiveSheet();
        $escaleras->setTitle($conHojas ? 'ESCALERAS ELECTRICAS-TEST' : 'OTRA HOJA');
        $this->encabezadosEscaleras($escaleras);
        $this->filaEscalera($escaleras, 4, 'Puerto Morelos', 'ENTERPRICE (#1)', 2024, 'AMBOS', 'SI', 'Buen estado', 'Buen estado', 45985, 'Primer equipo.');
        $this->filaEscalera($escaleras, 5, null, 'ENTERPRICE (#2)', 2024, 'AMBOS', 'SI', 'Buen estado', 'Buen estado', null, null);
        $this->filaEscalera($escaleras, 6, 'Tulum Aeropuerto', 'IBD2301245', 2024, 'ASCENSO/DESCENSO', 'SI', 'Buen estado', 'Operativo', '//', 'Fecha ilegible en el origen.');

        $elevadores = $spreadsheet->createSheet();
        $elevadores->setTitle($conHojas ? 'ELEVADORES-TEST' : 'OTRA HOJA 2');
        $this->encabezadosElevadores($elevadores);
        $this->filaElevador($elevadores, 4, 'Tulum', '57NI6384', 2024, 'ASC/DESC', 'SI', 45982, 'Buen estado', null);
        $this->filaElevador($elevadores, 5, null, '57NI6499', 2024, 'ASC/DESC', 'NO', 45982, 'Fuera de servicio', 'Atrapamiento de usuarios.');

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'controles-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->cleanup[] = $path;

        return $path;
    }

    private function encabezadosEscaleras(Worksheet $sheet): void
    {
        $sheet->fromArray(
            ['Estación', 'ID Escalera', 'Modelo', 'Año de instalación', 'Tipo (ASC./DESC.)', 'Operativo (SI/NO)', 'Estado de barandales', 'Estado de botón paro emergencia', 'Fecha de último mantenimiento', 'Observaciones'],
            null,
            'B3',
        );
    }

    private function encabezadosElevadores(Worksheet $sheet): void
    {
        $sheet->fromArray(
            ['Estación', 'ID Elevador', 'Modelo', 'Año de instalación', 'Tipo', 'Operativo (SI/NO)', 'Fecha de último mantenimiento', 'Estado de puertas, cabina, y botoneras', 'Observaciones'],
            null,
            'B3',
        );
    }

    private function filaEscalera(Worksheet $sheet, int $row, ?string $estacion, string $id, int $anio, string $tipo, string $operativo, string $barandales, string $botonParo, int|string|null $fecha, ?string $observaciones): void
    {
        if ($estacion !== null) {
            $sheet->setCellValue('B'.$row, $estacion);
        }
        $sheet->setCellValue('C'.$row, $id);
        $sheet->setCellValue('D'.$row, 'Modelo genérico');
        $sheet->setCellValue('E'.$row, $anio);
        $sheet->setCellValue('F'.$row, $tipo);
        $sheet->setCellValue('G'.$row, $operativo);
        $sheet->setCellValue('H'.$row, $barandales);
        $sheet->setCellValue('I'.$row, $botonParo);

        if (is_string($fecha)) {
            $sheet->setCellValueExplicit('J'.$row, $fecha, DataType::TYPE_STRING);
        } elseif ($fecha !== null) {
            $sheet->setCellValue('J'.$row, $fecha);
        }

        $sheet->setCellValue('K'.$row, $observaciones);
    }

    private function filaElevador(Worksheet $sheet, int $row, ?string $estacion, string $id, int $anio, string $tipo, string $operativo, int $fecha, string $estadoPuertas, ?string $observaciones): void
    {
        if ($estacion !== null) {
            $sheet->setCellValue('B'.$row, $estacion);
        }
        $sheet->setCellValue('C'.$row, $id);
        $sheet->setCellValue('D'.$row, 'Modelo genérico');
        $sheet->setCellValue('E'.$row, $anio);
        $sheet->setCellValue('F'.$row, $tipo);
        $sheet->setCellValue('G'.$row, $operativo);
        $sheet->setCellValue('H'.$row, $fecha);
        $sheet->setCellValue('I'.$row, $estadoPuertas);
        $sheet->setCellValue('J'.$row, $observaciones);
    }

    public function test_import_creates_records_resolving_the_carried_forward_estacion(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-controles', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, EscaleraElectrica::count());
        $this->assertSame(2, Elevador::count());

        $morelos = EscaleraElectrica::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Puerto Morelos'))->get();
        $this->assertCount(2, $morelos, 'the blank Estación cell on row 5 should inherit Puerto Morelos from row 4');
        $this->assertSame('ENTERPRICE (#2)', $morelos->last()->identificador);
    }

    public function test_import_skips_an_unparseable_maintenance_date_instead_of_storing_garbage(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-controles', ['path' => $this->buildFixture()])
            ->expectsOutputToContain('Fechas que no se pudieron leer')
            ->assertExitCode(Command::SUCCESS);

        $tulumAeropuerto = EscaleraElectrica::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Tulum Aeropuerto'))->firstOrFail();
        $this->assertNull($tulumAeropuerto->fecha_ultimo_mantenimiento);
    }

    public function test_import_converts_an_unformatted_excel_serial_into_a_real_date(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-controles', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $morelos = EscaleraElectrica::query()->where('identificador', 'ENTERPRICE (#1)')->firstOrFail();
        $this->assertSame('2025-11-24', $morelos->fecha_ultimo_mantenimiento->toDateString());

        $tulum = Elevador::query()->where('identificador', '57NI6384')->firstOrFail();
        $this->assertSame('2025-11-21', $tulum->fecha_ultimo_mantenimiento->toDateString());
    }

    public function test_reimport_replaces_previous_rows_instead_of_duplicating(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-controles', ['path' => $path])->assertExitCode(Command::SUCCESS);
        $this->artisan('app:import-controles', ['path' => $path])->assertExitCode(Command::SUCCESS);

        $this->assertSame(3, EscaleraElectrica::count());
        $this->assertSame(2, Elevador::count());
    }

    public function test_import_fails_when_the_expected_sheets_are_missing(): void
    {
        $this->artisan('app:import-controles', ['path' => $this->buildFixture(conHojas: false)])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EscaleraElectrica::count());
        $this->assertSame(0, Elevador::count());
    }
}
