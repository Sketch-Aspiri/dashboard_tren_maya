<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\User;
use App\Services\Secciones\ArchivoService;
use App\Services\Secciones\EnlaceNubeResolver;
use App\Services\Secciones\ImportacionService;
use App\Services\Secciones\NubeSyncService;
use App\Services\Secciones\OpcionesImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Documentos vinculados a un enlace compartido de la nube: se vuelven a
 * descargar solos y, si cambiaron, reemplazan el archivo y recargan sus tablas.
 */
class SincronizacionNubeTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const ENLACE_DRIVE = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view?usp=sharing';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    /**
     * @param  list<list<mixed>>  $filas
     */
    private function bytesExcel(array $filas): string
    {
        return (string) file_get_contents($this->excel($filas)->getPathname());
    }

    /**
     * Documento Excel ya guardado y cargado a la tabla de la sección, vinculado a la nube.
     *
     * @param  list<list<mixed>>  $filas
     */
    private function documentoVinculado(array $filas, string $enlace = self::ENLACE_DRIVE): SeccionArchivo
    {
        $usuario = $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = app(ArchivoService::class)->guardar($seccion, $this->excel($filas), $usuario);
        $importaciones = app(ImportacionService::class);
        $importacion = $importaciones->desdeArchivo($seccion, $archivo, $usuario);
        $propuesta = $importaciones->propuesta($importacion, 0);
        $importaciones->aplicar($importacion, new OpcionesImportacion(
            tabla: 0,
            filaEncabezado: $propuesta['fila_encabezado'],
            mapeo: array_map('strval', $propuesta['mapeo']),
            tipos: array_map(fn ($tipo) => TipoColumna::from($tipo), $propuesta['tipos']),
            modo: ModoImportacion::Agregar,
            llave: null,
        ), $usuario);

        $archivo->forceFill([
            'origen_url' => $enlace,
            'origen_proveedor' => 'google',
            'origen_hash' => hash_file('sha256', app(ArchivoService::class)->rutaAbsoluta($archivo)),
            'sincronizacion_activa' => true,
        ])->save();

        return $archivo->fresh();
    }

    public function test_resolves_each_provider_to_a_direct_download(): void
    {
        $resolver = app(EnlaceNubeResolver::class);

        $drive = $resolver->resolver(self::ENLACE_DRIVE);
        $this->assertSame('google', $drive['proveedor']);
        $this->assertSame('https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOpQ', $drive['descarga']);

        $hoja = $resolver->resolver('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQ/edit#gid=0');
        $this->assertSame('https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQ/export?format=xlsx', $hoja['descarga']);

        $onedrive = $resolver->resolver('https://1drv.ms/x/s!AbCdEf123');
        $this->assertSame('microsoft', $onedrive['proveedor']);
        $this->assertStringStartsWith('https://api.onedrive.com/v1.0/shares/u!', $onedrive['descarga']);
        $this->assertStringEndsWith('/root/content', $onedrive['descarga']);

        $sharepoint = $resolver->resolver('https://contoso.sharepoint.com/:x:/s/equipo/EaBc?e=xyz');
        $this->assertStringContainsString('download=1', $sharepoint['descarga']);

        $dropbox = $resolver->resolver('https://www.dropbox.com/s/abc123/archivo.xlsx?dl=0');
        $this->assertSame('dropbox', $dropbox['proveedor']);
        $this->assertStringContainsString('dl=1', $dropbox['descarga']);
        $this->assertStringNotContainsString('dl=0', $dropbox['descarga']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function enlacesRechazados(): array
    {
        return [
            'host desconocido' => ['https://evil.example.com/file/d/1AbCdEfGhIjKlMnOpQ'],
            'sin https' => ['http://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view'],
            'ip privada' => ['https://127.0.0.1/file.xlsx'],
            'host que solo termina parecido' => ['https://notdrive.google.com.evil.com/file/d/1AbCdEfGhIjKlMnOpQ'],
            'credenciales en la url' => ['https://user:pass@drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view'],
            'drive sin id' => ['https://drive.google.com/drive/my-drive'],
            'texto cualquiera' => ['no es un enlace'],
        ];
    }

    #[DataProvider('enlacesRechazados')]
    public function test_rejects_links_outside_the_allowed_providers(string $enlace): void
    {
        $this->expectException(ValidationException::class);

        app(EnlaceNubeResolver::class)->resolver($enlace);
    }

    public function test_replaces_document_and_reloads_tables_when_the_cloud_file_changed(): void
    {
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1'], ['A2', 'Escalera 2']]);
        $versionAnterior = $archivo->ruta;
        $nueva = $this->bytesExcel([['Folio', 'Equipo'], ['A1', 'Escalera 1 renovada'], ['A2', 'Escalera 2'], ['A3', 'Escalera 3']]);
        Http::fake(['*' => Http::response($nueva, 200)]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame('actualizado', $resultado);
        $archivo->refresh();
        $this->assertSame(hash('sha256', $nueva), $archivo->origen_hash);
        $this->assertNotSame($versionAnterior, $archivo->ruta);
        Storage::disk('local')->assertMissing($versionAnterior);
        Storage::disk('local')->assertExists($archivo->ruta);
        $this->assertNotNull($archivo->sincronizado_en);
        $this->assertNull($archivo->sincronizacion_error);

        $filas = $archivo->seccion->tablaActiva()->filas()->get()->pluck('datos');
        $this->assertCount(3, $filas);
        $this->assertContains('Escalera 1 renovada', $filas->pluck('equipo')->all());
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_sincronizado', 'subject_id' => $archivo->id]);
        $this->assertSame(2, $archivo->seccion->importaciones()->where('estado', EstadoImportacion::Aplicada->value)->count());
    }

    public function test_does_nothing_when_the_cloud_file_is_identical(): void
    {
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        $rutaAntes = $archivo->ruta;
        Http::fake(['*' => Http::response((string) file_get_contents(app(ArchivoService::class)->rutaAbsoluta($archivo)), 200)]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame('sin_cambios', $resultado);
        $archivo->refresh();
        $this->assertSame($rutaAntes, $archivo->ruta);
        $this->assertNotNull($archivo->sincronizado_en);
        $this->assertSame(1, $archivo->seccion->importaciones()->count());
    }

    public function test_keeps_previous_version_and_records_error_when_download_fails(): void
    {
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        $rutaAntes = $archivo->ruta;
        $hashAntes = $archivo->origen_hash;
        Http::fake(['*' => Http::response('', 500)]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame('error', $resultado);
        $archivo->refresh();
        $this->assertSame($rutaAntes, $archivo->ruta);
        $this->assertSame($hashAntes, $archivo->origen_hash);
        $this->assertNotNull($archivo->sincronizacion_error);
        Storage::disk('local')->assertExists($rutaAntes);
        $this->assertDatabaseHas('activity_log', ['event' => 'sincronizacion_fallida', 'subject_id' => $archivo->id]);
    }

    public function test_rejects_a_login_page_served_instead_of_the_document(): void
    {
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        $rutaAntes = $archivo->ruta;
        Http::fake(['*' => Http::response('<html><body>Inicia sesión</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame('error', $resultado);
        $this->assertSame($rutaAntes, $archivo->fresh()->ruta);
        $this->assertStringContainsString('documento', mb_strtolower((string) $archivo->fresh()->sincronizacion_error));
    }

    public function test_rejects_a_download_larger_than_the_allowed_size(): void
    {
        config(['secciones.max_archivo_kb' => 1]);
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        Http::fake(['*' => Http::response(str_repeat('x', 4096), 200)]);

        $this->assertSame('error', app(NubeSyncService::class)->sincronizar($archivo));
        $this->assertNotNull($archivo->fresh()->sincronizacion_error);
    }

    public function test_replaces_document_only_when_tables_cannot_be_matched_without_a_key(): void
    {
        $archivo = $this->documentoVinculado([['Columna uno', 'Columna dos'], ['x', 'y']]);
        $nueva = $this->bytesExcel([['Columna uno', 'Columna dos'], ['x', 'z'], ['w', 'v']]);
        Http::fake(['*' => Http::response($nueva, 200)]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame('actualizado', $resultado);
        $archivo->refresh();
        $this->assertSame(hash('sha256', $nueva), $archivo->origen_hash);
        $this->assertCount(1, $archivo->seccion->tablaActiva()->filas()->get());
        $this->assertNotNull($archivo->sincronizacion_error);
    }

    public function test_download_request_uses_the_resolved_direct_url(): void
    {
        $archivo = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        Http::fake(['*' => Http::response('', 500)]);

        app(NubeSyncService::class)->sincronizar($archivo);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://drive.google.com/uc?export=download&id=1AbCdEfGhIjKlMnOpQ');
    }

    public function test_command_syncs_only_active_linked_documents(): void
    {
        $vinculado = $this->documentoVinculado([['Folio', 'Equipo'], ['A1', 'Escalera 1']]);
        $pausado = $this->documentoVinculado([['Folio', 'Equipo'], ['B1', 'Escalera 9']]);
        $pausado->forceFill(['sincronizacion_activa' => false])->save();
        $sinVinculo = app(ArchivoService::class)->guardar(Seccion::factory()->create(), $this->excel([['a'], ['b']]), $vinculado->autor ?? User::factory()->create());
        Http::fake(['*' => Http::response($this->bytesExcel([['Folio', 'Equipo'], ['A1', 'Escalera 1'], ['A2', 'Nueva']]), 200)]);

        $this->artisan('secciones:sincronizar-nube')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertNotNull($vinculado->fresh()->sincronizado_en);
        $this->assertNull($pausado->fresh()->sincronizado_en);
        $this->assertNull($sinVinculo->fresh()->sincronizado_en);
    }

    public function test_admin_links_a_document_and_it_is_logged(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = app(ArchivoService::class)->guardar($seccion, $this->excel([['Folio'], ['A1']]), auth()->user());
        Http::fake(['*' => Http::response('', 500)]);

        $this->post(route('secciones.archivos.nube.vincular', [$seccion, $archivo]), ['enlace' => self::ENLACE_DRIVE])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $archivo->refresh();
        $this->assertSame(self::ENLACE_DRIVE, $archivo->origen_url);
        $this->assertSame('google', $archivo->origen_proveedor);
        $this->assertTrue($archivo->sincronizacion_activa);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_vinculado_nube', 'subject_id' => $archivo->id]);
    }

    public function test_link_with_disallowed_host_is_rejected_by_the_form_request(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = app(ArchivoService::class)->guardar($seccion, $this->excel([['Folio'], ['A1']]), auth()->user());

        $this->post(route('secciones.archivos.nube.vincular', [$seccion, $archivo]), ['enlace' => 'https://evil.example.com/x.xlsx'])
            ->assertSessionHasErrors('enlace');

        $this->assertNull($archivo->fresh()->origen_url);
    }

    public function test_user_without_role_cannot_link_sync_or_unlink(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol(null));
        $archivo = $this->documentoVinculadoSinSesion();
        $seccion = $archivo->seccion;

        $this->post(route('secciones.archivos.nube.vincular', [$seccion, $archivo]), ['enlace' => self::ENLACE_DRIVE])->assertForbidden();
        $this->post(route('secciones.archivos.nube.sincronizar', [$seccion, $archivo]))->assertForbidden();
        $this->delete(route('secciones.archivos.nube.desvincular', [$seccion, $archivo]))->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $archivo = $this->documentoVinculadoSinSesion();

        $this->post(route('secciones.archivos.nube.sincronizar', [$archivo->seccion, $archivo]))->assertRedirect(route('login'));
    }

    public function test_admin_can_sync_now_and_unlink(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = app(ArchivoService::class)->guardar($seccion, $this->excel([['Folio'], ['A1']]), auth()->user());
        $archivo->forceFill(['origen_url' => self::ENLACE_DRIVE, 'origen_proveedor' => 'google', 'sincronizacion_activa' => true])->save();
        Http::fake(['*' => Http::response($this->bytesExcel([['Folio'], ['A1'], ['A2']]), 200)]);

        $this->post(route('secciones.archivos.nube.sincronizar', [$seccion, $archivo]))->assertRedirect();
        $this->assertNotNull($archivo->fresh()->sincronizado_en);

        $this->delete(route('secciones.archivos.nube.desvincular', [$seccion, $archivo]))->assertRedirect();
        $this->assertNull($archivo->fresh()->origen_url);
        $this->assertFalse($archivo->fresh()->sincronizacion_activa);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_desvinculado_nube', 'subject_id' => $archivo->id]);
    }

    private function documentoVinculadoSinSesion(): SeccionArchivo
    {
        $seccion = Seccion::factory()->create();
        $archivo = app(ArchivoService::class)->guardar($seccion, $this->excel([['Folio'], ['A1']]), User::factory()->create());
        $archivo->forceFill(['origen_url' => self::ENLACE_DRIVE, 'origen_proveedor' => 'google', 'sincronizacion_activa' => true])->save();

        return $archivo;
    }
}
