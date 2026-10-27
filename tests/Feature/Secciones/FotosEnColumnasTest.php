<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Services\Secciones\ExtractorDocumentos;
use App\Services\Secciones\FotoService;
use App\Services\Secciones\NormalizadorValores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;
use ZipArchive;

/**
 * Columnas de tipo Foto: captura manual, fotos "en celda" de un Excel,
 * miniaturas privadas y su descarga autorizada.
 */
class FotosEnColumnasTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const NS_RICHDATA = 'http://schemas.microsoft.com/office/spreadsheetml/2017/richdata';

    public function test_photo_uploaded_by_hand_is_stored_as_private_thumbnail_and_shown_in_the_table(): void
    {
        Storage::fake();
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Nombre' => TipoColumna::Texto, 'Foto' => TipoColumna::Foto]);

        $this->post(route('secciones.filas.store', $seccion), [
            'datos' => ['nombre' => 'Ana'],
            'fotos' => ['foto' => UploadedFile::fake()->image('ana.png', 800, 600)],
        ])->assertRedirect();

        $foto = SeccionFila::query()->sole()->datos['foto'];
        $this->assertMatchesRegularExpression(FotoService::PATRON_NOMBRE, $foto);
        Storage::disk('local')->assertExists("secciones/fotos/{$foto}");

        $dimensiones = getimagesizefromstring(Storage::disk('local')->get("secciones/fotos/{$foto}"));
        $this->assertSame(320, $dimensiones[0]);

        $this->get(route('secciones.show', $seccion))->assertOk()
            ->assertSee(route('secciones.fotos.show', [$seccion, $foto]), false);
    }

    public function test_editing_without_a_new_photo_keeps_the_current_one(): void
    {
        Storage::fake();
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Nombre' => TipoColumna::Texto, 'Foto' => TipoColumna::Foto]);
        $this->post(route('secciones.filas.store', $seccion), [
            'datos' => ['nombre' => 'Ana'],
            'fotos' => ['foto' => UploadedFile::fake()->image('ana.jpg')],
        ]);
        $fila = SeccionFila::query()->sole();
        $original = $fila->datos['foto'];

        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['nombre' => 'Ana María']])->assertRedirect();

        $this->assertSame($original, $fila->refresh()->datos['foto']);
        $this->assertSame('Ana María', $fila->datos['nombre']);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake();
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Foto' => TipoColumna::Foto]);

        $this->post(route('secciones.filas.store', $seccion), [
            'fotos' => ['foto' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')],
        ])->assertInvalid(['fotos.foto']);

        $this->assertSame(0, SeccionFila::query()->count());
    }

    public function test_a_photo_column_never_accepts_free_text_or_paths(): void
    {
        $normalizador = new NormalizadorValores;

        $this->assertNull($normalizador->normalizar('../../.env', TipoColumna::Foto));
        $this->assertNull($normalizador->normalizar('https://x.test/a.jpg', TipoColumna::Foto));
        $nombre = sha1('x').'.jpg';
        $this->assertSame($nombre, $normalizador->normalizar(FotoService::PREFIJO.$nombre, TipoColumna::Foto));
        $this->assertSame(TipoColumna::Foto, $normalizador->inferirTipo([FotoService::PREFIJO.$nombre, FotoService::PREFIJO.sha1('y').'.jpg']));
    }

    public function test_photo_is_served_only_to_authenticated_users_and_only_for_valid_names(): void
    {
        Storage::fake();
        $seccion = Seccion::factory()->create();
        $nombre = app(FotoService::class)->guardar($this->png());

        $this->get(route('secciones.fotos.show', [$seccion, $nombre]))->assertRedirect(route('login'));

        $this->comoAdministrador();
        $this->get(route('secciones.fotos.show', [$seccion, $nombre]))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(route('secciones.fotos.show', [$seccion, sha1('no-existe').'.jpg']))->assertNotFound();
        $this->get("/secciones/{$seccion->id}/fotos/..%2F..%2F.env")->assertNotFound();
    }

    public function test_excel_with_in_cell_photos_creates_a_photo_column_and_links_each_row(): void
    {
        Storage::fake();
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->excelConFotoEnCelda();

        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$archivo]])->assertCreated();

        $importacion = SeccionImportacion::query()->sole();
        $propuesta = $this->get(route('secciones.importaciones.show', [$seccion, $importacion]))->assertOk()->viewData('propuesta');
        $this->assertSame('foto', $propuesta['tipos'][1]);

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), [
            'tabla' => 0,
            'fila_encabezado' => 0,
            'mapeo' => ['nueva', 'nueva'],
            'tipos' => ['texto', 'foto'],
            'modo' => 'agregar',
            'llave' => null,
        ])->assertRedirect();

        $filas = SeccionFila::query()->orderBy('id')->get();
        $this->assertCount(2, $filas);
        $this->assertMatchesRegularExpression(FotoService::PATRON_NOMBRE, $filas[0]->datos['foto']);
        Storage::disk('local')->assertExists('secciones/fotos/'.$filas[0]->datos['foto']);
        // La segunda persona no trae foto.
        $this->assertNull($filas[1]->datos['foto'] ?? null);
        // La ruta interna de la foto no entra al buscador de la tabla.
        $this->assertStringNotContainsString($filas[0]->datos['foto'], $filas[0]->texto_busqueda);
    }

    public function test_extractor_ignores_a_corrupt_in_cell_image(): void
    {
        Storage::fake();
        $archivo = $this->excelConFotoEnCelda('esto no es una imagen');

        $tablas = app(ExtractorDocumentos::class)->tablas($archivo->getPathname(), 'xlsx');

        $this->assertNotEmpty($tablas);
        $this->assertStringNotContainsString(FotoService::PREFIJO, json_encode($tablas));
    }

    private function png(): string
    {
        $imagen = imagecreatetruecolor(40, 30);
        ob_start();
        imagepng($imagen);

        return (string) ob_get_clean();
    }

    /**
     * Excel de dos personas; la celda B2 (Ana) lleva una foto "en celda" tal
     * como la guarda Excel (xl/richData), que PhpSpreadsheet lee como #VALUE!.
     */
    private function excelConFotoEnCelda(?string $contenidoImagen = null): UploadedFile
    {
        $base = $this->excel([['Nombre', 'Foto'], ['Ana', 'x'], ['Luis', null]], 'personal.xlsx');
        $zip = new ZipArchive;
        $zip->open($base->getPathname());

        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $hoja = preg_replace('/<c r="B2"[^>]*>.*?<\/c>/s', '<c r="B2" t="e" vm="1"><v>#VALUE!</v></c>', $hoja);
        $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);

        $ns = self::NS_RICHDATA;
        $zip->addFromString('xl/metadata.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<metadata xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:xlrd="'.$ns.'">'
            .'<metadataTypes count="1"><metadataType name="XLRICHVALUE"/></metadataTypes>'
            .'<futureMetadata name="XLRICHVALUE" count="1"><bk><extLst><ext uri="{3e2802c4-a4d2-4d8b-9148-e3be6c30e623}"><xlrd:rvb i="0"/></ext></extLst></bk></futureMetadata>'
            .'<valueMetadata count="1"><bk><rc t="1" v="0"/></bk></valueMetadata></metadata>');
        $zip->addFromString('xl/richData/rdrichvaluestructure.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rvStructures xmlns="'.$ns.'" count="1"><s t="_localImage"><k n="_rvRel:LocalImageIdentifier" t="i"/><k n="CalcOrigin" t="i"/></s></rvStructures>');
        $zip->addFromString('xl/richData/rdrichvalue.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<rvData xmlns="'.$ns.'" count="1"><rv s="0"><v>0</v><v>5</v></rv></rvData>');
        $zip->addFromString('xl/richData/richValueRel.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<richValueRels xmlns="http://schemas.microsoft.com/office/spreadsheetml/2022/richvaluerel" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><rel r:id="rId1"/></richValueRels>');
        $zip->addFromString('xl/richData/_rels/richValueRel.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/image1.png"/></Relationships>');
        $zip->addFromString('xl/media/image1.png', $contenidoImagen ?? $this->png());
        $zip->close();

        return $base;
    }
}
