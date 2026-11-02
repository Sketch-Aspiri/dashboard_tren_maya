<?php

namespace Tests\Feature\Secciones;

use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Services\Secciones\ExtractorDocumentos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Los Excel institucionales suelen fusionar Estación / No. siniestro /
 * Reporte en vertical sobre varias filas de FECHA / SITUACIÓN. Sin expandir
 * esas fusiones, las filas de continuación llegan vacías y la tabla muestra "—".
 */
class ExtractorCeldasFusionadasTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    /**
     * Excel con celdas A2:A4, B2:B4 y C2:C4 fusionadas (un siniestro con
     * tres fechas de seguimiento).
     */
    private function excelConFusionesVerticales(): UploadedFile
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Siniestros');
        $hoja->fromArray([
            ['Estacion', 'No. SINIESTRO', 'REPORTE', 'FECHA', 'SITUACION'],
            ['Nicolás Bravo', 'STRENMAYA-00053/2026', 'Con fecha 24 Jun. 2026, de los doce (12) bienes.', '2026-05-02', 'En trámite'],
            [null, null, null, '2026-08-17', 'En trámite'],
            [null, null, null, '2026-08-24', 'En trámite'],
            ['Tulum', 'STRENMAYA-00074/2026', 'Otro reporte.', '2026-06-17', 'En trámite'],
        ], null, 'A1', true);
        $hoja->mergeCells('A2:A4');
        $hoja->mergeCells('B2:B4');
        $hoja->mergeCells('C2:C4');

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);

        return new UploadedFile($ruta, 'siniestros.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function test_extractor_fills_vertically_merged_cells_into_continuation_rows(): void
    {
        $tablas = app(ExtractorDocumentos::class)->tablas(
            $this->excelConFusionesVerticales()->getPathname(),
            'xlsx',
        );

        $this->assertCount(1, $tablas);
        $filas = $tablas[0]['filas'];
        $this->assertCount(5, $filas);

        // Encabezado intacto.
        $this->assertSame('Estacion', $filas[0][0]);

        // Primera fila del bloque fusionado.
        $this->assertSame('Nicolás Bravo', $filas[1][0]);
        $this->assertSame('STRENMAYA-00053/2026', $filas[1][1]);
        $this->assertSame('Con fecha 24 Jun. 2026, de los doce (12) bienes.', $filas[1][2]);
        $this->assertSame('2026-05-02', $filas[1][3]);

        // Filas de continuación: deben heredar Estación / siniestro / reporte.
        $this->assertSame('Nicolás Bravo', $filas[2][0]);
        $this->assertSame('STRENMAYA-00053/2026', $filas[2][1]);
        $this->assertSame('Con fecha 24 Jun. 2026, de los doce (12) bienes.', $filas[2][2]);
        $this->assertSame('2026-08-17', $filas[2][3]);
        $this->assertSame('En trámite', $filas[2][4]);

        $this->assertSame('Nicolás Bravo', $filas[3][0]);
        $this->assertSame('STRENMAYA-00053/2026', $filas[3][1]);
        $this->assertSame('2026-08-24', $filas[3][3]);

        // Fila siguiente (sin fusión) intacta.
        $this->assertSame('Tulum', $filas[4][0]);
        $this->assertSame('STRENMAYA-00074/2026', $filas[4][1]);
    }

    public function test_importing_merged_excel_persists_station_on_every_date_row(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->excelConFusionesVerticales();

        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$archivo]])
            ->assertCreated();

        $importacion = SeccionImportacion::query()->sole();
        $opciones = [
            'tabla' => 0,
            'fila_encabezado' => 0,
            'mapeo' => ['nueva', 'nueva', 'nueva', 'nueva', 'nueva'],
            'tipos' => ['texto', 'texto', 'texto', 'fecha', 'texto'],
            'modo' => 'agregar',
            'llave' => null,
        ];

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $opciones)
            ->assertRedirect(route('secciones.show', $seccion));

        $filas = SeccionFila::query()->orderBy('id')->get();
        $this->assertCount(4, $filas);

        $nicolas = $filas->take(3);
        foreach ($nicolas as $fila) {
            $this->assertSame('Nicolás Bravo', $fila->datos['estacion']);
            $this->assertSame('STRENMAYA-00053/2026', $fila->datos['no_siniestro']);
            $this->assertStringContainsString('doce (12) bienes', (string) $fila->datos['reporte']);
        }

        $this->assertSame(['2026-05-02', '2026-08-17', '2026-08-24'], $nicolas->pluck('datos.fecha')->all());
        $this->assertSame('Tulum', $filas[3]->datos['estacion']);

        // Cada fecha del mismo siniestro aparece con Estación rellenada (no "—").
        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertSee('Nicolás Bravo')
            ->assertSee('STRENMAYA-00053/2026')
            ->assertSee('02/05/2026')
            ->assertSee('17/08/2026')
            ->assertSee('24/08/2026')
            ->assertSee('Tulum');
    }
}
