<?php

namespace Tests\Feature\Secciones;

use App\Models\Seccion;
use App\Models\SeccionArchivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

class VistaPreviaDocumentosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function subir(Seccion $seccion, UploadedFile $archivo): SeccionArchivo
    {
        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$archivo]])->assertCreated();

        return SeccionArchivo::query()->latest('id')->firstOrFail();
    }

    private function vistaPrevia(Seccion $seccion, SeccionArchivo $archivo, array $query = [])
    {
        return $this->getJson(route('secciones.archivos.vista-previa', [$seccion, $archivo] + $query));
    }

    public function test_xlsx_preview_returns_rows_and_sheet_list(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle('Uno')->fromArray([['Nombre', 'Edad'], ['Ana <b>', 30]], null, 'A1', true);
        $libro->createSheet()->setTitle('Dos')->fromArray([['X'], ['y']], null, 'A1', true);
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);
        $archivo = $this->subir($seccion, new UploadedFile($ruta, 'libro.xlsx', null, null, true));

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tipo', 'tabla')
            ->assertJsonPath('data.hojas', ['Uno', 'Dos'])
            ->assertJsonPath('data.hoja', 0)
            ->assertJsonPath('data.filas.1.0', 'Ana <b>');

        $this->vistaPrevia($seccion, $archivo, ['hoja' => 1])->assertOk()->assertJsonPath('data.filas.1.0', 'y');
        $this->assertTrue(Activity::query()->where('event', 'documento_visto')->exists());
    }

    public function test_csv_preview_returns_rows(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, UploadedFile::fake()->createWithContent('datos.csv', "a,b\n1,2\n"));

        $this->vistaPrevia($seccion, $archivo)->assertOk()->assertJsonPath('data.tipo', 'tabla')->assertJsonPath('data.filas.1.1', '2');
    }

    public function test_pdf_and_image_preview_point_to_inline_url_with_server_side_mime(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $imagen = $this->subir($seccion, UploadedFile::fake()->image('plano.png'));

        $this->vistaPrevia($seccion, $imagen)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'imagen')
            ->assertJsonPath('data.url', route('secciones.archivos.show', [$seccion, $imagen, 'ver' => 1]));

        $this->get(route('secciones.archivos.show', [$seccion, $imagen, 'ver' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        $pdf = $this->subir($seccion, UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n%fake\n"));
        $this->vistaPrevia($seccion, $pdf)->assertOk()->assertJsonPath('data.tipo', 'pdf');
    }

    public function test_docx_preview_returns_text_and_tables(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $word = new PhpWord;
        $s = $word->addSection();
        $s->addText('Hola mundo');
        $t = $s->addTable();
        $t->addRow();
        $t->addCell()->addText('c1');
        $t->addCell()->addText('c2');
        $ruta = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($ruta);
        $archivo = $this->subir($seccion, new UploadedFile($ruta, 'oficio.docx', null, null, true));

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'documento')
            ->assertJsonPath('data.tablas.0.filas.0', ['c1', 'c2']);
        $this->assertStringContainsString('Hola mundo', $this->vistaPrevia($seccion, $archivo)->json('data.texto'));
    }

    public function test_unsupported_type_returns_download_message(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, $this->excel([['a'], ['b']], 'x.xlsx'));
        $archivo->forceFill(['extension' => 'zip'])->save();

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'no_soportado')
            ->assertJsonPath('data.url_descarga', route('secciones.archivos.show', [$seccion, $archivo]));
    }

    public function test_preview_requires_role_and_matching_section(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, UploadedFile::fake()->createWithContent('datos.csv', "a,b\n1,2\n"));

        $this->vistaPrevia(Seccion::factory()->create(), $archivo)->assertNotFound();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));
        $this->vistaPrevia($seccion, $archivo)->assertForbidden();
    }

    public function test_guest_cannot_preview(): void
    {
        $seccion = Seccion::factory()->create();
        $this->getJson(route('secciones.archivos.vista-previa', [$seccion, 1]))->assertUnauthorized();
    }

    public function test_preview_caps_rows_and_columns(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        config(['secciones.vista_previa_filas' => 3, 'secciones.vista_previa_columnas' => 2]);
        $archivo = $this->subir($seccion, UploadedFile::fake()->createWithContent('g.csv', "a,b,c\n1,2,3\n4,5,6\n7,8,9\n"));

        $respuesta = $this->vistaPrevia($seccion, $archivo)->assertOk()->assertJsonPath('data.truncado', true);
        $this->assertCount(3, $respuesta->json('data.filas'));
        $this->assertCount(2, $respuesta->json('data.filas.0'));
    }

    public function test_formulas_are_not_recalculated_the_cached_value_is_shown(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setCellValue('A1', 'Total');
        $hoja->setCellValue('A2', '=1+1');
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);

        // Se altera el valor guardado de la fórmula: si se recalculara saldría 2.
        $zip = new \ZipArchive;
        $zip->open($ruta);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $this->assertStringContainsString('<v>2</v>', $xml);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('<v>2</v>', '<v>42</v>', $xml));
        $zip->close();
        $archivo = $this->subir($seccion, new UploadedFile($ruta, 'formulas.xlsx', null, null, true));

        $this->vistaPrevia($seccion, $archivo)->assertOk()->assertJsonPath('data.filas.1.0', '42');
    }

    public function test_hidden_sheets_are_not_listed_or_previewed(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $libro = new Spreadsheet;
        $libro->getActiveSheet()->setTitle('Oculta')->fromArray([['secreto'], ['x']], null, 'A1', true);
        $libro->getActiveSheet()->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $libro->createSheet()->setTitle('Visible')->fromArray([['pub'], ['y']], null, 'A1', true);
        $libro->setActiveSheetIndex(1);
        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);
        $archivo = $this->subir($seccion, new UploadedFile($ruta, 'oculta.xlsx', null, null, true));

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('data.hojas', ['Visible'])
            ->assertJsonPath('data.filas.0.0', 'pub');
        $this->vistaPrevia($seccion, $archivo, ['hoja' => 5])->assertOk()->assertJsonPath('data.filas.0.0', 'pub');
    }

    public function test_zip_container_with_a_suspicious_compression_ratio_is_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, $this->excel([['a'], ['b']], 'x.xlsx'));

        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', 5 * 1024 * 1024));
        $zip->close();
        Storage::disk('local')->put($archivo->ruta, (string) file_get_contents($ruta));

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'no_soportado')
            ->assertJsonPath('data.mensaje', __('No se puede mostrar este archivo de forma segura.'));
    }

    public function test_zip_container_with_an_oversized_entry_is_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, $this->excel([['a'], ['b']], 'x.xlsx'));

        // Sin compresión (ratio 1) pero por encima del tope por entrada (50 MB).
        $ruta = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new \ZipArchive;
        $zip->open($ruta, \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', random_bytes(2 * 1024 * 1024));
        $zip->setCompressionName('xl/worksheets/sheet1.xml', \ZipArchive::CM_STORE);
        $zip->close();
        Storage::disk('local')->put($archivo->ruta, (string) file_get_contents($ruta));
        config(['secciones.vista_previa_zip_entrada_mb' => 1]);

        $this->vistaPrevia($seccion, $archivo)
            ->assertOk()
            ->assertJsonPath('data.tipo', 'no_soportado')
            ->assertJsonPath('data.mensaje', __('No se puede mostrar este archivo de forma segura.'));
    }

    public function test_only_the_first_sheet_view_of_a_readable_document_is_audited(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, $this->excel([['a'], ['b']], 'x.xlsx'));

        $this->vistaPrevia($seccion, $archivo, ['hoja' => 1])->assertOk();
        $this->assertSame(0, Activity::query()->where('event', 'documento_visto')->count());

        $this->vistaPrevia($seccion, $archivo)->assertOk();
        $this->assertSame(1, Activity::query()->where('event', 'documento_visto')->count());

        $archivo->forceFill(['extension' => 'zip'])->save();
        $this->vistaPrevia($seccion, $archivo)->assertOk()->assertJsonPath('data.tipo', 'no_soportado');
        $this->assertSame(1, Activity::query()->where('event', 'documento_visto')->count());
    }

    public function test_preview_is_rate_limited_to_20_per_minute(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->subir($seccion, UploadedFile::fake()->createWithContent('datos.csv', "a,b\n1,2\n"));
        RateLimiter::clear('secciones-vista-previa');

        for ($i = 0; $i < 20; $i++) {
            $this->vistaPrevia($seccion, $archivo)->assertOk();
        }

        $this->vistaPrevia($seccion, $archivo)->assertTooManyRequests();
    }
}
