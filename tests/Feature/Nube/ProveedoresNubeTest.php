<?php

namespace Tests\Feature\Nube;

use App\Enums\MotivoOmision;
use App\Enums\ProveedorNube;
use App\Models\Seccion;
use App\Models\SeccionOrigen;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Nube\Proveedores\Dropbox;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\GoogleDrive;
use App\Services\Nube\Proveedores\MicrosoftGraph;
use App\Services\Nube\ProveedorNubeFactory;
use App\Services\Secciones\NubeSyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithNube;
use Tests\TestCase;

/**
 * Los tres proveedores con cuenta: inspeccionar (archivo/carpeta), listar
 * recursivo y paginado, omitidos con motivo y descarga.
 */
class ProveedoresNubeTest extends TestCase
{
    use InteractsWithNube, RefreshDatabase;

    private const MB = 1048576;

    private string $destino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destino = tempnam(sys_get_temp_dir(), 'nube');
    }

    protected function tearDown(): void
    {
        @unlink($this->destino);

        parent::tearDown();
    }

    // --- Fábrica y contrato -------------------------------------------------

    public function test_factory_resolves_the_provider_by_origin_and_by_enum(): void
    {
        $fabrica = app(ProveedorNubeFactory::class);
        $google = $this->cuentaDeNube(ProveedorNube::Google);

        $this->assertInstanceOf(GoogleDrive::class, $fabrica->para($this->origenDeNube($google, 'archivo')));
        $this->assertInstanceOf(EnlacePublico::class, $fabrica->para($this->origenDeNube($google, 'archivo', ['nube_cuenta_id' => null])));
        $this->assertInstanceOf(MicrosoftGraph::class, $fabrica->para(ProveedorNube::Microsoft));
        $this->assertInstanceOf(GoogleDrive::class, $fabrica->para(ProveedorNube::Google));
        $this->assertInstanceOf(Dropbox::class, $fabrica->para(ProveedorNube::Dropbox));
    }

    public function test_public_link_provider_inspects_a_single_file(): void
    {
        $remoto = app(EnlacePublico::class)->inspeccionar('https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view', null);

        $this->assertSame(OrigenRemoto::ARCHIVO, $remoto->tipo);
        $this->assertSame(1, $remoto->archivos);
    }

    public function test_providers_with_account_require_a_connected_account(): void
    {
        $this->expectException(NubeSyncException::class);

        app(MicrosoftGraph::class)->inspeccionar('https://contoso.sharepoint.com/x', null);
    }

    // --- Microsoft Graph ----------------------------------------------------

    private function fakeGraphCarpeta(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();

            return match (true) {
                str_contains($url, '/shares/') => Http::response(['id' => 'ROOT', 'name' => 'Extintores', 'folder' => ['childCount' => 4], 'parentReference' => ['driveId' => 'DRV1']]),
                str_contains($url, 'skiptoken') => Http::response(['value' => [
                    ['id' => 'F3', 'name' => 'tres.docx', 'size' => 30, 'cTag' => 'c3', 'file' => ['mimeType' => 'x']],
                ]]),
                str_contains($url, '/items/SUB/children') => Http::response(['value' => [
                    ['id' => 'F2', 'name' => 'dos.pdf', 'size' => 20, 'cTag' => 'c2', 'file' => ['mimeType' => 'application/pdf'], 'lastModifiedDateTime' => '2026-10-01T10:00:00Z'],
                    ['id' => 'F4', 'name' => 'enorme.pdf', 'size' => 200 * self::MB, 'cTag' => 'c4', 'file' => ['mimeType' => 'application/pdf']],
                ]]),
                str_contains($url, '/items/ROOT/children') => Http::response(['value' => [
                    ['id' => 'F1', 'name' => 'uno.xlsx', 'size' => 10, 'cTag' => 'c1', 'file' => ['mimeType' => 'x']],
                    ['id' => 'SUB', 'name' => 'Sub', 'folder' => ['childCount' => 2]],
                    ['id' => 'Z', 'name' => 'todo.zip', 'size' => 5, 'file' => ['mimeType' => 'application/zip']],
                    ['id' => 'E', 'name' => 'programa.exe', 'size' => 5, 'file' => ['mimeType' => 'x']],
                ], '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/drives/DRV1/items/ROOT/children?$skiptoken=abc']),
                default => Http::response([], 404),
            };
        });
    }

    public function test_graph_inspects_a_file_with_the_account_token(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $enlace = 'https://contoso.sharepoint.com/:x:/s/eq/EaBc?e=1';
        Http::fake(['graph.microsoft.com/*' => Http::response([
            'id' => 'ITEM1', 'name' => 'Plan.xlsx', 'size' => 1000, 'cTag' => 'c1', 'file' => ['mimeType' => 'x'], 'parentReference' => ['driveId' => 'DRV1'],
        ])]);

        $remoto = app(MicrosoftGraph::class)->inspeccionar($enlace, $cuenta);

        $this->assertSame(OrigenRemoto::ARCHIVO, $remoto->tipo);
        $this->assertSame('DRV1', $remoto->driveId);
        $this->assertSame('ITEM1', $remoto->itemId);
        $this->assertSame('Plan.xlsx', $remoto->nombre);
        $this->assertSame(1, $remoto->archivos);
        $codificado = 'u!'.rtrim(strtr(base64_encode($enlace), '+/', '-_'), '=');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), "/shares/{$codificado}/driveItem") && $r->hasHeader('Authorization', 'Bearer token-acceso-falso'));
    }

    public function test_graph_inspects_a_folder_recursively_with_pagination_and_omissions(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $this->fakeGraphCarpeta();

        $remoto = app(MicrosoftGraph::class)->inspeccionar('https://contoso.sharepoint.com/:f:/s/eq/Carp', $cuenta);

        $this->assertSame(OrigenRemoto::CARPETA, $remoto->tipo);
        $this->assertSame('ROOT', $remoto->itemId);
        $this->assertSame(3, $remoto->archivos); // uno.xlsx, dos.pdf, tres.docx
        $this->assertEquals([
            MotivoOmision::Zip->value => 1,
            MotivoOmision::ExtensionNoPermitida->value => 1,
            MotivoOmision::Grande->value => 1,
        ], $remoto->omitidos);
    }

    public function test_graph_lists_a_folder_origin_with_paths_versions_and_ids(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_drive_id' => 'DRV1', 'remoto_item_id' => 'ROOT']);
        $this->fakeGraphCarpeta();

        $listado = app(MicrosoftGraph::class)->listarConResumen($origen);

        $rutas = collect($listado->archivos)->mapWithKeys(fn (ArchivoRemoto $a): array => [$a->rutaRelativa => $a->version])->all();
        $this->assertSame(['uno.xlsx' => 'c1', 'Sub/dos.pdf' => 'c2', 'tres.docx' => 'c3'], $rutas);
        $this->assertSame('DRV1', $listado->archivos[0]->driveId);
        $this->assertCount(3, app(MicrosoftGraph::class)->listar($origen));
    }

    public function test_graph_respects_the_file_cap_and_the_depth_limit(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_drive_id' => 'DRV1', 'remoto_item_id' => 'ROOT']);
        $this->fakeGraphCarpeta();

        config(['secciones.nube.max_profundidad' => 1]);
        $sinProfundidad = app(MicrosoftGraph::class)->listarConResumen($origen);
        $this->assertEquals([MotivoOmision::Profundidad->value => 1, MotivoOmision::Zip->value => 1, MotivoOmision::ExtensionNoPermitida->value => 1], $sinProfundidad->omitidos);
        $this->assertCount(2, $sinProfundidad->archivos);

        config(['secciones.nube.max_profundidad' => 10, 'secciones.nube.max_archivos_por_origen' => 1]);
        $tope = app(MicrosoftGraph::class)->listarConResumen($origen);
        $this->assertCount(1, $tope->archivos);
        $this->assertArrayHasKey(MotivoOmision::TopeArchivos->value, $tope->omitidos);
    }

    public function test_graph_can_skip_subfolders(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_drive_id' => 'DRV1', 'remoto_item_id' => 'ROOT', 'incluir_subcarpetas' => false]);
        $this->fakeGraphCarpeta();

        $rutas = array_map(fn (ArchivoRemoto $a): string => $a->rutaRelativa, app(MicrosoftGraph::class)->listar($origen));

        $this->assertSame(['uno.xlsx', 'tres.docx'], $rutas);
    }

    public function test_graph_lists_and_downloads_a_single_file_origin(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'archivo', ['remoto_drive_id' => 'DRV1', 'remoto_item_id' => 'ITEM1']);
        Http::fake([
            'graph.microsoft.com/v1.0/drives/DRV1/items/ITEM1/content' => Http::response('', 302, ['Location' => 'https://contoso-my.sharepoint.com/dl?tempauth=1']),
            'graph.microsoft.com/v1.0/drives/DRV1/items/ITEM1*' => Http::response(['id' => 'ITEM1', 'name' => 'Plan.xlsx', 'size' => 10, 'cTag' => 'c9', 'file' => ['mimeType' => 'x']]),
            'contoso-my.sharepoint.com/*' => Http::response('BYTES', 200),
        ]);

        $archivos = app(MicrosoftGraph::class)->listar($origen);
        app(MicrosoftGraph::class)->descargar($origen, $archivos[0], $this->destino);

        $this->assertCount(1, $archivos);
        $this->assertSame('c9', $archivos[0]->version);
        $this->assertSame('BYTES', file_get_contents($this->destino));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sharepoint.com') && ! $r->hasHeader('Authorization'));
    }

    public function test_graph_origin_without_a_connected_account_fails_clearly(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['nube_cuenta_id' => null]);

        $this->expectException(NubeSyncException::class);

        app(MicrosoftGraph::class)->listar($origen);
    }

    // --- Google Drive -------------------------------------------------------

    private function fakeDriveCarpeta(): void
    {
        Http::fake(function (Request $r) {
            $url = $r->url();
            $q = $r['q'] ?? '';

            return match (true) {
                str_contains($url, '/files/FOLDER12345') && ! str_contains($url, 'export') => Http::response(['id' => 'FOLDER12345', 'name' => 'Manuales', 'mimeType' => 'application/vnd.google-apps.folder']),
                str_contains($q, "'FOLDER12345' in parents") && ! isset($r['pageToken']) => Http::response(['nextPageToken' => 'PAG2', 'files' => [
                    ['id' => 'FILE000001', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '10', 'md5Checksum' => 'md5a'],
                    ['id' => 'SUBFOLDER01', 'name' => 'Sub', 'mimeType' => 'application/vnd.google-apps.folder'],
                    ['id' => 'DOC0000001', 'name' => 'Informe', 'mimeType' => 'application/vnd.google-apps.document', 'version' => '5'],
                    ['id' => 'SHORT00001', 'name' => 'Atajo', 'mimeType' => 'application/vnd.google-apps.shortcut'],
                    ['id' => 'FORM000001', 'name' => 'Encuesta', 'mimeType' => 'application/vnd.google-apps.form'],
                ]]),
                str_contains($q, "'FOLDER12345' in parents") => Http::response(['files' => [
                    ['id' => 'ZIP0000001', 'name' => 'x.zip', 'mimeType' => 'application/zip', 'size' => '5', 'md5Checksum' => 'z'],
                    ['id' => 'BIG0000001', 'name' => 'grande.pdf', 'mimeType' => 'application/pdf', 'size' => (string) (150 * self::MB), 'md5Checksum' => 'b'],
                ]]),
                str_contains($q, "'SUBFOLDER01' in parents") => Http::response(['files' => [
                    ['id' => 'SHEET00001', 'name' => 'Datos', 'mimeType' => 'application/vnd.google-apps.spreadsheet', 'version' => '9'],
                ]]),
                default => Http::response([], 404),
            };
        });
    }

    public function test_drive_inspects_a_file_from_its_url(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'FILE000001', 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '10', 'md5Checksum' => 'm'])]);

        $remoto = app(GoogleDrive::class)->inspeccionar('https://drive.google.com/file/d/FILE000001/view?usp=sharing', $cuenta);

        $this->assertSame(OrigenRemoto::ARCHIVO, $remoto->tipo);
        $this->assertSame('FILE000001', $remoto->itemId);
        $this->assertSame(1, $remoto->archivos);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/drive/v3/files/FILE000001') && $r->hasHeader('Authorization', 'Bearer token-acceso-falso'));
    }

    public function test_drive_inspects_a_folder_with_pagination_native_docs_and_omissions(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        $this->fakeDriveCarpeta();

        $remoto = app(GoogleDrive::class)->inspeccionar('https://drive.google.com/drive/folders/FOLDER12345?usp=sharing', $cuenta);

        $this->assertSame(OrigenRemoto::CARPETA, $remoto->tipo);
        $this->assertSame('Manuales', $remoto->nombre);
        $this->assertSame(3, $remoto->archivos); // a.pdf, Informe.docx, Datos.xlsx
        $this->assertEquals([
            MotivoOmision::AccesoDirectoGoogle->value => 1,
            MotivoOmision::ExtensionNoPermitida->value => 1,
            MotivoOmision::Zip->value => 1,
            MotivoOmision::Grande->value => 1,
        ], $remoto->omitidos);
        Http::assertSent(fn (Request $r): bool => ($r['q'] ?? '') === "'FOLDER12345' in parents and trashed=false");
    }

    public function test_drive_lists_with_export_names_and_versions(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_item_id' => 'FOLDER12345']);
        $this->fakeDriveCarpeta();

        $archivos = collect(app(GoogleDrive::class)->listar($origen))->keyBy('rutaRelativa');

        $this->assertSame(['a.pdf', 'Sub/Datos.xlsx', 'Informe.docx'], $archivos->keys()->all());
        $this->assertSame('md5a', $archivos['a.pdf']->version);
        $this->assertSame('v5', $archivos['Informe.docx']->version);
        $this->assertSame('v9', $archivos['Sub/Datos.xlsx']->version);
    }

    public function test_drive_downloads_normal_files_with_alt_media_and_exports_native_ones(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_item_id' => 'FOLDER12345']);
        Http::fake(fn () => Http::response('DATOS', 200));
        $proveedor = app(GoogleDrive::class);

        $proveedor->descargar($origen, new ArchivoRemoto('FILE000001', 'a.pdf', 'a.pdf', mime: 'application/pdf'), $this->destino);
        $proveedor->descargar($origen, new ArchivoRemoto('DOC0000001', 'Informe.docx', 'Informe.docx', mime: 'application/vnd.google-apps.document'), $this->destino);

        $this->assertSame('DATOS', file_get_contents($this->destino));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/files/FILE000001') && $r['alt'] === 'media');
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/files/DOC0000001/export')
            && $r['mimeType'] === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_drive_never_puts_an_unvalidated_id_in_the_query(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_item_id' => "x' or name contains 'a"]);
        Http::fake();

        try {
            app(GoogleDrive::class)->listar($origen);
            $this->fail('Debió lanzar NubeSyncException.');
        } catch (NubeSyncException) {
            Http::assertNothingSent();
        }
    }

    public function test_drive_rejects_urls_that_are_not_from_drive(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);

        $this->expectException(NubeSyncException::class);

        app(GoogleDrive::class)->inspeccionar('https://evil.example.com/file/d/FILE000001/view', $cuenta);
    }

    // --- Dropbox ------------------------------------------------------------

    private function fakeDropboxCarpeta(): void
    {
        Http::fake([
            'api.dropboxapi.com/2/sharing/get_shared_link_metadata' => Http::response(['.tag' => 'folder', 'id' => 'id:carpeta1', 'name' => 'Expedientes']),
            'api.dropboxapi.com/2/files/list_folder/continue' => Http::response(['has_more' => false, 'cursor' => 'c2', 'entries' => [
                ['.tag' => 'file', 'id' => 'id:f3', 'name' => 'tres.xlsx', 'path_display' => '/Sub/tres.xlsx', 'size' => 30, 'content_hash' => 'h3', 'server_modified' => '2026-10-01T10:00:00Z'],
                ['.tag' => 'file', 'id' => 'id:f4', 'name' => 'comprimido.zip', 'path_display' => '/comprimido.zip', 'size' => 4, 'content_hash' => 'h4'],
            ]]),
            'api.dropboxapi.com/2/files/list_folder' => Http::response(['has_more' => true, 'cursor' => 'c1', 'entries' => [
                ['.tag' => 'file', 'id' => 'id:f1', 'name' => 'uno.pdf', 'path_display' => '/uno.pdf', 'size' => 10, 'content_hash' => 'h1'],
                ['.tag' => 'folder', 'id' => 'id:sub', 'name' => 'Sub', 'path_display' => '/Sub'],
                ['.tag' => 'file', 'id' => 'id:f2', 'name' => 'enorme.pdf', 'path_display' => '/enorme.pdf', 'size' => 300 * self::MB, 'content_hash' => 'h2'],
            ]]),
        ]);
    }

    public function test_dropbox_inspects_a_folder_with_continue_pagination(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        $this->fakeDropboxCarpeta();

        $remoto = app(Dropbox::class)->inspeccionar('https://www.dropbox.com/scl/fo/abc/xyz?rlkey=k', $cuenta);

        $this->assertSame(OrigenRemoto::CARPETA, $remoto->tipo);
        $this->assertSame('id:carpeta1', $remoto->itemId);
        $this->assertSame('Expedientes', $remoto->nombre);
        $this->assertSame(2, $remoto->archivos);
        $this->assertEquals([MotivoOmision::Grande->value => 1, MotivoOmision::Zip->value => 1], $remoto->omitidos);
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/files/list_folder')
            && $r['shared_link']['url'] === 'https://www.dropbox.com/scl/fo/abc/xyz?rlkey=k'
            && $r['recursive'] === true && $r['path'] === '');
        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/files/list_folder/continue') && $r['cursor'] === 'c1');
    }

    public function test_dropbox_inspects_a_single_file_link(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        Http::fake(['api.dropboxapi.com/*' => Http::response(['.tag' => 'file', 'id' => 'id:f1', 'name' => 'Plan.xlsx', 'size' => 99, 'rev' => 'r1'])]);

        $remoto = app(Dropbox::class)->inspeccionar('https://www.dropbox.com/s/abc/Plan.xlsx?dl=0', $cuenta);

        $this->assertSame(OrigenRemoto::ARCHIVO, $remoto->tipo);
        $this->assertSame('Plan.xlsx', $remoto->nombre);
        $this->assertSame(1, $remoto->archivos);
    }

    public function test_dropbox_lists_with_relative_paths_and_content_hash(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['enlace_original' => 'https://www.dropbox.com/scl/fo/abc/xyz?rlkey=k']);
        $this->fakeDropboxCarpeta();

        $archivos = collect(app(Dropbox::class)->listar($origen))->keyBy('rutaRelativa');

        $this->assertSame(['uno.pdf', 'Sub/tres.xlsx'], $archivos->keys()->all());
        $this->assertSame('h1', $archivos['uno.pdf']->version);
        $this->assertSame('id:f3', $archivos['Sub/tres.xlsx']->id);
    }

    public function test_dropbox_downloads_with_the_shared_link_arguments_header(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['enlace_original' => 'https://www.dropbox.com/scl/fo/abc/xyz?rlkey=k']);
        Http::fake(['content.dropboxapi.com/*' => Http::response('PDF-BYTES', 200)]);

        app(Dropbox::class)->descargar($origen, new ArchivoRemoto('id:f3', 'tres.xlsx', 'Sub/ñandú.xlsx'), $this->destino);

        $this->assertSame('PDF-BYTES', file_get_contents($this->destino));
        Http::assertSent(function (Request $r): bool {
            $cabecera = $r->header('Dropbox-API-Arg')[0] ?? '';

            return $r->method() === 'POST'
                && str_ends_with($r->url(), '/sharing/get_shared_link_file')
                && ! preg_match('/[^\x20-\x7E]/', $cabecera) // solo ASCII, como exige Dropbox
                && json_decode($cabecera, true) === ['url' => 'https://www.dropbox.com/scl/fo/abc/xyz?rlkey=k', 'path' => '/Sub/ñandú.xlsx']
                && $r->hasHeader('Authorization', 'Bearer token-acceso-falso');
        });
    }

    public function test_dropbox_single_file_download_has_no_path_argument(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        $origen = $this->origenDeNube($cuenta, 'archivo', ['enlace_original' => 'https://www.dropbox.com/s/abc/Plan.xlsx?dl=0']);
        Http::fake(['content.dropboxapi.com/*' => Http::response('X', 200)]);

        app(Dropbox::class)->descargar($origen, new ArchivoRemoto('id:f1', 'Plan.xlsx', 'Plan.xlsx'), $this->destino);

        Http::assertSent(fn (Request $r): bool => json_decode($r->header('Dropbox-API-Arg')[0], true) === ['url' => 'https://www.dropbox.com/s/abc/Plan.xlsx?dl=0']);
    }

    public function test_origins_keep_working_with_the_existing_public_link_contract(): void
    {
        $origen = SeccionOrigen::forceCreate([
            'seccion_id' => Seccion::factory()->create()->id,
            'tipo' => 'archivo',
            'proveedor' => 'google',
            'enlace_original' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view',
            'nombre' => 'a.xlsx',
        ]);

        $listado = app(ProveedorNubeFactory::class)->para($origen)->listarConResumen($origen);

        $this->assertCount(1, $listado->archivos);
        $this->assertSame([], $listado->omitidos);
    }
}
