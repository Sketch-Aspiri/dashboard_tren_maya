<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\PtarEvidencia;
use App\Models\PtarRegistro;
use App\Services\Ptar\PtarImportador;
use App\Services\Ptar\PtarNormalizador;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Carga del Excel de estatus de las PTAR: normalización del texto libre y lectura por bloques de 5 filas.
 */
class PtarImportTest extends TestCase
{
    use RefreshDatabase;

    private Estacion $pm;

    private Estacion $bacalar;

    private string $archivo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->pm = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23', 'orden' => 7]);
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29', 'orden' => 1]);
        $this->archivo = tempnam(sys_get_temp_dir(), 'ptar').'.xlsx';
    }

    protected function tearDown(): void
    {
        @unlink($this->archivo);

        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function estatusProvider(): array
    {
        return [
            'operando' => ['operando ', 'operando'],
            'con prefijo' => ['OP: OPERANDO', 'operando'],
            'femenino' => ['Operativa', 'operando'],
            'con errata' => ['opernado', 'operando'],
            'manual' => ['Operando de modo manual', 'operando'],
            'parcial por conteo' => ['1 de 2 fuera de servicio', 'parcial'],
            'fuera' => ['Fuera de servicio', 'fuera_servicio'],
            'vacío' => [null, 'sin_dato'],
            'raro' => ['pendiente', 'sin_dato'],
        ];
    }

    #[DataProvider('estatusProvider')]
    public function test_equipment_status_text_is_normalized(?string $texto, string $esperado): void
    {
        $this->assertSame($esperado, PtarNormalizador::estatus($texto));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null, 2: string|null}>
     */
    public static function fechaProvider(): array
    {
        return [
            'fecha en texto' => ['29/09/2026', '2026-09-29', null],
            'varias fechas toma la última' => ['11/03/2026 y 11/04/2026', '2026-04-11', '11/03/2026 y 11/04/2026'],
            'con frase' => ['Cada dos dias (05/08/2026)', '2026-08-05', 'Cada dos dias (05/08/2026)'],
            'sin fecha' => ['Diario', null, 'Diario'],
            'año incompleto' => ['11/03/206', null, '11/03/206'],
            'no aplica' => ['N/A', null, 'N/A'],
            'vacío' => [null, null, null],
            'objeto fecha' => [CarbonImmutable::parse('2026-10-05'), '2026-10-05', null],
        ];
    }

    #[DataProvider('fechaProvider')]
    public function test_maintenance_dates_are_normalized(mixed $valor, ?string $fecha, ?string $nota): void
    {
        $this->assertSame([$fecha, $nota], PtarNormalizador::fecha($valor));
    }

    public function test_operation_mode_comes_from_the_marked_columns(): void
    {
        $this->assertSame(['automatico', null], PtarNormalizador::modo('X', null, null));
        $this->assertSame(['manual', 'Automático y manual'], PtarNormalizador::modo('x', 'x', null));
        $this->assertSame(['fuera_servicio', null], PtarNormalizador::modo(null, null, 'X'));
        $this->assertSame(['sin_dato', null], PtarNormalizador::modo(null, null, null));
        $this->assertSame(['manual', 'Manual: Sopladores. Fuera de servicio: Válvula'], PtarNormalizador::modo(null, 'Sopladores', 'Válvula'));
        $this->assertSame(['automatico', 'Fuera de servicio: SOPLADOR DE 3 CABALLOS'], PtarNormalizador::modo('X', null, 'SOPLADOR DE 3 CABALLOS'));
    }

    /**
     * @param  array<int, string|null>  $equipos
     */
    private function hoja(Spreadsheet $libro, string $titulo, int $mes, array $equipos, string $observacionMes, bool $primera = false)
    {
        $hoja = $primera ? $libro->getActiveSheet() : $libro->createSheet();
        $hoja->setTitle($titulo);
        $hoja->setCellValue('D6', 'Estación');
        $hoja->setCellValue('I6', 'Equipo eléctrico');
        $hoja->setCellValue('N6', 'Observaciones generales al 7 de agosto 2026');
        $hoja->setCellValue('P6', 'Observaciones generales al 7 de mes');

        $hoja->setCellValue('B8', '23')->setCellValue('D8', 'Puerto Morelos')->setCellValue('E8', 'x');
        foreach ($equipos as $i => $estatus) {
            $hoja->setCellValue('J'.(8 + $i), $estatus);
        }
        $hoja->setCellValue('K8', 'Soplador fuera de servicio');
        $hoja->setCellValue('L8', 'Cárcamo')->setCellValue('M8', '29/09/2026');
        $hoja->setCellValue('L9', 'Tolva')->setCellValue('M9', 'N/A');
        $hoja->setCellValue('L10', 'cárcamo')->setCellValue('M10', '05/10/2026');
        $hoja->setCellValue('N8', 'Texto del mes anterior');
        $hoja->setCellValue('P8', $observacionMes);

        $hoja->setCellValue('B13', '29')->setCellValue('D13', 'Bacalar')->setCellValue('E13', 'X')->setCellValue('G13', 'SOPLADOR');
        $hoja->setCellValue('N13', 'Solo mes anterior');

        $hoja->setCellValue('B18', '22')->setCellValue('D18', 'Cancún');

        return $hoja;
    }

    private function libro(): void
    {
        $libro = new Spreadsheet;
        $this->hoja($libro, 'JUNIO 2026', 6, [], '', true)->setCellValue('I6', 'Observaciones 05 mzo 2025');
        $octubre = $this->hoja($libro, 'OCTUBRE 2026', 10, ['OP: OPERANDO', 'operando', '1 de 2 fuera de servicio', null, 'Operativo'], 'Observación de octubre');
        $this->hoja($libro, 'NOVIEMBRE 2026 ', 11, ['operando', 'operando', 'operando', 'operando', 'operando'], 'Observación de noviembre');

        if (function_exists('imagecreatetruecolor')) {
            $png = tempnam(sys_get_temp_dir(), 'img').'.png';
            imagepng(imagecreatetruecolor(8, 8), $png);
            $foto = new Drawing;
            $foto->setPath($png)->setCoordinates('Q8')->setWorksheet($octubre);

            // Una imagen que no sea JPG/PNG (p. ej. GIF) no se importa.
            $gif = tempnam(sys_get_temp_dir(), 'img').'.gif';
            imagegif(imagecreatetruecolor(8, 8), $gif);
            $otra = new Drawing;
            $otra->setPath($gif)->setCoordinates('S9')->setWorksheet($octubre);
        }

        (new Xlsx($libro))->save($this->archivo);
    }

    public function test_reading_maps_blocks_to_plants_and_skips_unknown_sheets_and_stations(): void
    {
        $this->libro();

        $leido = app(PtarImportador::class)->leer($this->archivo);

        $this->assertCount(4, $leido['registros']);
        $this->assertCount(5, $leido['avisos']);
        $this->assertStringContainsString('JUNIO 2026', $leido['avisos'][0]);

        $pmOctubre = collect($leido['registros'])->first(fn ($r) => $r['estacion'] === 'Puerto Morelos' && $r['mes'] === 10);
        $this->assertSame('automatico', $pmOctubre['modo']);
        $this->assertSame(['bomba_sumergible' => 'operando', 'bomba_centrifuga' => 'operando', 'sopladores' => 'parcial', 'difusores' => 'sin_dato', 'panel_control' => 'operando'], $pmOctubre['equipos']);
        $this->assertSame('Soplador fuera de servicio', $pmOctubre['observaciones_equipos']);
        $this->assertSame('Observación de octubre', $pmOctubre['observaciones_generales']);
        $this->assertSame('aprobada', $pmOctubre['estado']);

        $actividades = collect($pmOctubre['actividades'])->keyBy('actividad');
        $this->assertSame('2026-10-05', $actividades['Cárcamo']['ultima_fecha']);
        $this->assertSame('N/A', $actividades['Tolva']['nota']);
        $this->assertCount(2, $actividades);
    }

    public function test_last_month_stays_draft_and_falls_back_to_the_previous_column_when_empty(): void
    {
        $this->libro();

        $leido = app(PtarImportador::class)->leer($this->archivo);
        $bacalar = collect($leido['registros'])->first(fn ($r) => $r['estacion'] === 'Bacalar' && $r['mes'] === 11);

        $this->assertSame('borrador', $bacalar['estado']);
        $this->assertSame('Solo mes anterior', $bacalar['observaciones_generales']);
        $this->assertSame('automatico', $bacalar['modo']);
        $this->assertSame('Fuera de servicio: SOPLADOR', $bacalar['modo_detalle']);
    }

    public function test_applying_creates_records_equipment_activities_and_photos_without_overwriting(): void
    {
        $this->libro();
        $importador = app(PtarImportador::class);
        $leido = $importador->leer($this->archivo);

        $resultado = $importador->aplicar($leido['registros']);

        $this->assertSame(4, $resultado['creados']);
        $this->assertSame(0, $resultado['omitidos']);
        $this->assertSame(4, PtarRegistro::query()->count());
        $this->assertSame(20, PtarRegistro::query()->withCount('equipos')->get()->sum('equipos_count'));

        $octubre = PtarRegistro::query()->where(['estacion_id' => $this->pm->id, 'mes' => 10])->firstOrFail();
        $this->assertSame('aprobada', $octubre->estado);
        $this->assertSame('parcial', $octubre->equipos->firstWhere('equipo', 'sopladores')->estatus);

        if (function_exists('imagecreatetruecolor')) {
            $this->assertSame(1, $resultado['fotos']);
            $foto = PtarEvidencia::query()->firstOrFail();
            $this->assertSame('importacion', $foto->origen);
            Storage::disk('local')->assertExists($foto->ruta);
        }

        $octubre->update(['observaciones_generales' => 'Editado por la estación']);
        $otra = $importador->aplicar($leido['registros']);
        $this->assertSame(0, $otra['creados']);
        $this->assertSame(4, $otra['omitidos']);
        $this->assertSame('Editado por la estación', $octubre->fresh()->observaciones_generales);
    }

    public function test_command_simulates_without_saving_and_then_loads(): void
    {
        $this->libro();

        $this->artisan('ptar:cargar-historico', ['archivo' => $this->archivo, '--simular' => true])
            ->expectsOutputToContain('Simulación: 4 registro(s)')
            ->assertSuccessful();
        $this->assertSame(0, PtarRegistro::query()->count());

        $this->artisan('ptar:cargar-historico', ['archivo' => $this->archivo])
            ->expectsOutputToContain('Creados: 4')
            ->assertSuccessful();
        $this->assertSame(4, PtarRegistro::query()->count());

        $this->artisan('ptar:cargar-historico', ['archivo' => '/no/existe.xlsx'])->assertFailed();
    }
}
