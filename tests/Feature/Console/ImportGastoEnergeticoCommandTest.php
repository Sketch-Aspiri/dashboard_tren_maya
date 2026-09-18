<?php

namespace Tests\Feature\Console;

use App\Enums\TipoServicio;
use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Feature coverage for app:import-gasto-energetico. Uses a tiny SYNTHETIC
 * .xlsx fixture built at test time — never the real ANEXO B (see
 * .claude/rules/testing.md "Dummy Data").
 */
class ImportGastoEnergeticoCommandTest extends TestCase
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

    /**
     * "ZONA ORIENTE" sheet like the real one: two stacked tables. Months run
     * Nov, Dic, (blank spacer column), Ene, Feb under a single "2025" year
     * header — so Ene/Feb must land in 2026 by sequence, not by header.
     */
    private function buildFixture(bool $conHoja = true): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle($conHoja ? 'ZONA ORIENTE' : 'OTRA HOJA');

        $this->tabla($sheet, 3, 'Pago de servicio de agua', [
            ['EZE', '63943', 'CAPA', [0, 1392.02, null, '$1,154.76', 200], null],
            ['23_Puerto Morelos.', '548436', 'AGUAKAN', ['$ -', 10, null, 20, 30], "Primer párrafo.\n\nSegundo   párrafo."],
            ['31_Nicolas Bravo.', 'En trámite', 'CONAGUA', [null, null, null, null, 5], null],
            ['99_Estacion Fantasma.', '1', 'X', [1, 1, null, 1, 1], null],
        ]);

        $this->tabla($sheet, 12, 'Pago de servicio de energía eléctrica', [
            ['31_Nicolas Bravo.', 796251001099.0, 'CFE', [185148, 46153, null, 47475, 565811], null],
            ['26_Tulum  Aeropuerto.', '812240902448', 'CFE', [0, 0, null, 55161, 53801], null],
        ]);

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'gasto-energetico-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $this->cleanup[] = $path;

        return $path;
    }

    /**
     * @param  list<array{0: string, 1: string|float, 2: string, 3: list<int|float|string|null>, 4: string|null}>  $filas
     */
    private function tabla(Worksheet $sheet, int $filaTitulo, string $titulo, array $filas): void
    {
        $sheet->setCellValue('B'.$filaTitulo, $titulo);
        $sheet->fromArray(['Estación', 'Fecha y núm. de contrato', 'Proveedor', '2025'], null, 'B'.($filaTitulo + 1));
        $sheet->fromArray(['Nov', 'Dic', null, 'Ene', 'Febr', 'OBSERVACIONES'], null, 'E'.($filaTitulo + 2));

        foreach ($filas as $indice => [$estacion, $contrato, $proveedor, $montos, $observaciones]) {
            $row = $filaTitulo + 3 + $indice;
            $sheet->setCellValue('B'.$row, $estacion);
            $sheet->setCellValue('C'.$row, $contrato);
            $sheet->setCellValue('D'.$row, $proveedor);

            foreach ($montos as $offset => $monto) {
                $celda = chr(ord('E') + $offset).$row;

                if ($monto === null) {
                    continue;
                }

                is_string($monto)
                    ? $sheet->setCellValueExplicit($celda, $monto, DataType::TYPE_STRING)
                    : $sheet->setCellValue($celda, $monto);
            }

            $sheet->setCellValue('J'.$row, $observaciones);
        }
    }

    private function seedEstaciones(): void
    {
        foreach (['Edificio Zonal Este', 'Puerto Morelos', 'Kohunlich', 'Tulum Aeropuerto', 'Tulum'] as $orden => $nombre) {
            Estacion::factory()->create(['nombre' => $nombre, 'orden' => $orden + 1]);
        }
    }

    private function servicio(string $estacion, TipoServicio $tipo): ServicioEstacion
    {
        return ServicioEstacion::query()
            ->whereHas('estacion', fn ($query) => $query->where('nombre', $estacion))
            ->where('tipo', $tipo->value)
            ->firstOrFail();
    }

    public function test_import_creates_servicios_resolving_prefixes_and_aliases(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-gasto-energetico', ['path' => $this->buildFixture()])
            ->expectsOutputToContain('99_Estacion Fantasma.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(5, ServicioEstacion::count());

        $eze = $this->servicio('Edificio Zonal Este', TipoServicio::Agua);
        $this->assertSame('CAPA', $eze->proveedor);
        $this->assertSame('63943', $eze->contrato);

        $this->servicio('Kohunlich', TipoServicio::Agua);
        $this->servicio('Kohunlich', TipoServicio::EnergiaElectrica);
        $this->servicio('Tulum Aeropuerto', TipoServicio::EnergiaElectrica);
    }

    public function test_import_derives_the_year_from_the_month_sequence_not_the_merged_header(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-gasto-energetico', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $pagos = $this->servicio('Kohunlich', TipoServicio::EnergiaElectrica)->pagos()
            ->orderBy('anio')->orderBy('mes')->get()
            ->map(fn ($pago) => "{$pago->anio}-{$pago->mes}:".(float) $pago->monto)->all();

        $this->assertSame(['2025-11:185148', '2025-12:46153', '2026-1:47475', '2026-2:565811'], $pagos);
    }

    public function test_import_parses_text_amounts_zero_dashes_and_skips_blanks(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-gasto-energetico', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $eze = $this->servicio('Edificio Zonal Este', TipoServicio::Agua)->pagos->keyBy(fn ($p) => "{$p->anio}-{$p->mes}");
        $this->assertSame(1154.76, (float) $eze['2026-1']->monto);
        $this->assertSame(1392.02, (float) $eze['2025-12']->monto);

        $morelos = $this->servicio('Puerto Morelos', TipoServicio::Agua)->pagos->keyBy(fn ($p) => "{$p->anio}-{$p->mes}");
        $this->assertSame(0.0, (float) $morelos['2025-11']->monto);

        // Nicolás Bravo agua has a single value (Feb 2026); blank months get no row.
        $this->assertSame(1, $this->servicio('Kohunlich', TipoServicio::Agua)->pagos()->count());
    }

    public function test_import_keeps_paragraph_breaks_in_observaciones_and_long_contract_numbers(): void
    {
        $this->seedEstaciones();

        $this->artisan('app:import-gasto-energetico', ['path' => $this->buildFixture()])->assertExitCode(Command::SUCCESS);

        $this->assertSame("Primer párrafo.\n\nSegundo párrafo.", $this->servicio('Puerto Morelos', TipoServicio::Agua)->observaciones);
        $this->assertSame('796251001099', $this->servicio('Kohunlich', TipoServicio::EnergiaElectrica)->contrato);
    }

    public function test_reimport_updates_in_place_without_duplicating_rows(): void
    {
        $this->seedEstaciones();
        $path = $this->buildFixture();

        $this->artisan('app:import-gasto-energetico', ['path' => $path])->assertExitCode(Command::SUCCESS);
        $pagos = PagoServicio::count();

        $this->artisan('app:import-gasto-energetico', ['path' => $path])->assertExitCode(Command::SUCCESS);

        $this->assertSame(5, ServicioEstacion::count());
        $this->assertSame($pagos, PagoServicio::count());
    }

    public function test_import_fails_when_the_zona_oriente_sheet_is_missing(): void
    {
        $this->artisan('app:import-gasto-energetico', ['path' => $this->buildFixture(conHoja: false)])
            ->assertExitCode(Command::FAILURE);

        $this->assertSame(0, ServicioEstacion::count());
    }
}
