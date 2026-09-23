<?php

namespace Tests\Feature\Console;

use App\Models\Estacion;
use App\Models\EstatusViaAnden;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-estatus-vias-andenes. Uses a tiny
 * SYNTHETIC .xlsx fixture built at test time — never the real ANEXO (see
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportEstatusViasAndenesCommandTest extends TestCase
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
        foreach (['Puerto Morelos', 'Limones', 'Kohunlich'] as $orden => $nombre) {
            Estacion::factory()->create(['nombre' => $nombre, 'orden' => $orden + 1]);
        }
    }

    /**
     * Same shape as the real hoja "ZO_*": row 3 headers, "Estación" only on
     * the first row of each station, plus an old hidden month sheet that
     * must be ignored and a station that is not in the catalog.
     */
    private function buildFixture(bool $conHojaVisible = true): string
    {
        $spreadsheet = new Spreadsheet;

        $anterior = $spreadsheet->getActiveSheet();
        $anterior->setTitle('ZO_ENERO_26');
        $this->encabezados($anterior);
        $this->fila($anterior, 4, 'Puerto Morelos', 9, 'A', 'HOJA OCULTA', 'HOJA OCULTA', null);
        $anterior->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $actual = $spreadsheet->createSheet();
        $actual->setTitle($conHojaVisible ? 'ZO_MARZO_26' : 'OTRA HOJA');
        $this->encabezados($actual);
        $this->fila($actual, 4, 'Puerto Morelos', 4, 'B', 'Terminado', 'Terminado (fuera de operacion)', null);
        $this->fila($actual, 5, null, 1, 'A', 'Terminado', 'Operativas', "Primera línea.\nSegunda línea.");
        $this->fila($actual, 6, 'Limones / Chacchoben', 2, null, 'Buen estado', 'Buen estado', null);
        $this->fila($actual, 7, 'Nicolas Bravo / Kohunlich', 3, 'A', 'Terminado', 'Operativas', null);
        $this->fila($actual, 8, 'Estación Fantasma', 1, 'A', 'Terminado', 'Operativas', null);
        $this->fila($actual, 9, null, 2, 'B', 'Terminado', 'Operativas', null);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'vias-andenes-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->cleanup[] = $path;

        return $path;
    }

    private function encabezados(Worksheet $sheet): void
    {
        $sheet->fromArray(
            ['Estación', 'Esquema de Vías', 'Vía', 'Andén', 'Andén', 'Via', 'Señaletica', 'Teleindicadores', 'Pruebas Gálibo', 'Riesgos / Obstaculos', 'Comentarios Especificos'],
            null,
            'C3',
        );
    }

    private function fila(Worksheet $sheet, int $row, ?string $estacion, int $via, ?string $anden, string $estatusAnden, string $estatusVia, ?string $comentarios): void
    {
        if ($estacion !== null) {
            $sheet->setCellValue('C'.$row, $estacion);
        }
        $sheet->setCellValue('E'.$row, $via);
        $sheet->setCellValue('F'.$row, $anden);
        $sheet->setCellValue('G'.$row, $estatusAnden);
        $sheet->setCellValue('H'.$row, $estatusVia);
        $sheet->setCellValue('I'.$row, 'Instaladas');
        $sheet->setCellValue('J'.$row, 'Instalados, sin operar');
        $sheet->setCellValue('K'.$row, 'Realizadas');
        $sheet->setCellValue('L'.$row, 'Ninguno');
        $sheet->setCellValue('M'.$row, $comentarios);
    }

    public function test_import_creates_one_row_per_via_carrying_the_estacion_forward(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-estatus-vias-andenes', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $morelos = EstatusViaAnden::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Puerto Morelos'))->orderBy('via')->get();
        $this->assertSame([1, 4], $morelos->pluck('via')->all(), 'the blank Estación cell on row 5 should inherit Puerto Morelos');
        $this->assertSame('B', $morelos->last()->anden);
        $this->assertSame('Terminado (fuera de operacion)', $morelos->last()->estatus_via);
        $this->assertSame("Primera línea.\nSegunda línea.", $morelos->first()->comentarios);
        $this->assertSame('Ninguno', $morelos->first()->riesgos_obstaculos);
    }

    public function test_import_resolves_composite_names_and_the_nicolas_bravo_alias(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-estatus-vias-andenes', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $this->assertSame(1, EstatusViaAnden::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Limones'))->count());
        $this->assertNull(EstatusViaAnden::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Limones'))->firstOrFail()->anden);
        $this->assertSame(1, EstatusViaAnden::query()->whereHas('estacion', fn ($q) => $q->where('nombre', 'Kohunlich'))->count());
    }

    public function test_import_skips_an_unrecognized_estacion_and_its_carried_rows(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-estatus-vias-andenes', ['path' => $this->buildFixture()])
            ->expectsOutputToContain('Estación Fantasma')
            ->assertExitCode(Command::SUCCESS);

        // 2 Puerto Morelos + 1 Limones + 1 Kohunlich; the two Fantasma rows are dropped.
        $this->assertSame(4, EstatusViaAnden::count());
    }

    public function test_import_ignores_hidden_month_sheets(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-estatus-vias-andenes', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $this->assertSame(0, EstatusViaAnden::query()->where('estatus_anden', 'HOJA OCULTA')->count());
    }

    public function test_reimport_replaces_previous_rows_instead_of_duplicating(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-estatus-vias-andenes', ['path' => $path])->assertExitCode(Command::SUCCESS);
        $this->artisan('app:import-estatus-vias-andenes', ['path' => $path])->assertExitCode(Command::SUCCESS);

        $this->assertSame(4, EstatusViaAnden::count());
    }

    public function test_import_fails_when_there_is_no_visible_zo_sheet(): void
    {
        $this->artisan('app:import-estatus-vias-andenes', ['path' => $this->buildFixture(conHojaVisible: false)])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, EstatusViaAnden::count());
    }
}
