<?php

namespace Tests\Feature\Nube;

use App\Enums\CodigoErrorNube;
use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\MotivoTruncado;
use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\ClasificadorErrorNube;
use App\Services\Nube\ClienteNube;
use App\Services\Nube\CuentaNubeService;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Nube\NubeOAuthException;
use App\Services\Nube\Proveedores\Dropbox;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\GoogleDrive;
use App\Services\Nube\Proveedores\MicrosoftGraph;
use App\Services\Secciones\NubeSyncException;
use App\Services\Secciones\OrigenService;
use App\Services\Secciones\SincronizadorOrigen;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithNube;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Concerns\ProveedorNubeFalso;
use Tests\TestCase;

/**
 * Endurecimiento de la nube tras la auditoría: topes de listado, Bearer solo
 * a hosts de API, uso de cuentas por rol, refresh de tokens, errores tipados
 * y errores de credenciales junto al proveedor correcto.
 */
class EndurecimientoNubeTest extends TestCase
{
    use InteractsWithNube, InteractsWithSecciones, RefreshDatabase;

    private const GRAPH = 'https://graph.microsoft.com/v1.0';

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

    private function origenCarpeta(NubeCuenta $cuenta, string $itemId = 'ROOT'): SeccionOrigen
    {
        return $this->origenDeNube($cuenta, SeccionOrigen::TIPO_CARPETA, [
            'remoto_drive_id' => 'DRV1',
            'remoto_item_id' => $itemId,
        ]);
    }

    private function peticionesA(string $fragmento): int
    {
        $total = 0;
        Http::assertSent(function (Request $r) use ($fragmento, &$total): bool {
            $total += str_contains($r->url(), $fragmento) ? 1 : 0;

            return true;
        });

        return $total;
    }

    // --- 1. Topes de listado -------------------------------------------------

    public function test_graph_stops_after_the_request_cap_and_marks_the_listing_truncated(): void
    {
        config(['secciones.nube.max_peticiones_listado' => 3]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        Http::fake(fn () => Http::response([
            'value' => [['id' => 'F'.random_int(1, 999999), 'name' => 'a.pdf', 'size' => 1, 'file' => ['mimeType' => 'x']]],
            '@odata.nextLink' => self::GRAPH.'/drives/DRV1/items/ROOT/children?$skiptoken='.random_int(1, 999999),
        ]));

        $listado = app(MicrosoftGraph::class)->listarConResumen($this->origenCarpeta($cuenta));

        $this->assertSame(MotivoTruncado::Peticiones, $listado->truncado);
        $this->assertSame(3, $this->peticionesA('/children'));
        $this->assertCount(3, $listado->archivos);
    }

    public function test_graph_stops_after_the_reviewed_entries_cap_counting_skipped_ones(): void
    {
        config(['secciones.nube.max_archivos_por_origen' => 2]); // tope de entradas = 20
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $exes = array_map(fn (int $i): array => ['id' => 'E'.$i, 'name' => "x{$i}.exe", 'size' => 1, 'file' => ['mimeType' => 'x']], range(1, 50));
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => $exes])]);

        $listado = app(MicrosoftGraph::class)->listarConResumen($this->origenCarpeta($cuenta));

        $this->assertSame(MotivoTruncado::Entradas, $listado->truncado);
        $this->assertSame(20, $listado->omitidos['extension_no_permitida']);
    }

    public function test_exceeding_the_file_cap_marks_the_listing_truncated_and_nothing_is_archived(): void
    {
        config(['secciones.nube.max_archivos_por_origen' => 2]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        $origen = $this->origenDeNube($cuenta, 'carpeta', ['remoto_drive_id' => 'DRV1', 'remoto_item_id' => 'ROOT']);
        $pdfs = array_map(fn (int $i): array => ['id' => 'P'.$i, 'name' => "p{$i}.pdf", 'size' => 1, 'cTag' => 'c'.$i, 'file' => ['mimeType' => 'application/pdf']], range(1, 3));
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => $pdfs])]);

        $listado = app(MicrosoftGraph::class)->listarConResumen($origen);

        $this->assertCount(2, $listado->archivos);
        $this->assertSame(MotivoTruncado::Archivos, $listado->truncado);
    }

    public function test_graph_stops_when_the_time_limit_is_reached(): void
    {
        config(['secciones.nube.max_segundos_listado' => 0]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        Http::fake();

        $listado = app(MicrosoftGraph::class)->listarConResumen($this->origenCarpeta($cuenta));

        $this->assertSame(MotivoTruncado::Tiempo, $listado->truncado);
        Http::assertNothingSent();
    }

    public function test_graph_cuts_cycles_made_by_shortcuts_to_an_already_visited_folder(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        Http::fake(['graph.microsoft.com/*' => Http::response(['value' => [
            ['id' => 'ATAJO', 'name' => 'Atajo', 'remoteItem' => ['id' => 'ROOT', 'folder' => ['childCount' => 1], 'parentReference' => ['driveId' => 'DRV1']]],
            ['id' => 'F1', 'name' => 'uno.pdf', 'size' => 1, 'file' => ['mimeType' => 'x']],
        ]])]);

        $listado = app(MicrosoftGraph::class)->listarConResumen($this->origenCarpeta($cuenta));

        $this->assertNull($listado->truncado);
        $this->assertCount(1, $listado->archivos);
        $this->assertSame(1, $this->peticionesA('/children'));
    }

    public function test_graph_refuses_a_next_link_to_another_host(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        Http::fake(['graph.microsoft.com/*' => Http::response([
            'value' => [],
            '@odata.nextLink' => 'https://evil.example.com/steal',
        ])]);

        $this->expectException(NubeSyncException::class);

        try {
            app(MicrosoftGraph::class)->listarConResumen($this->origenCarpeta($cuenta));
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_inspection_uses_the_lower_request_cap_and_reports_it(): void
    {
        config(['secciones.nube.max_peticiones_inspeccion' => 2]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), '/shares/')) {
                return Http::response(['id' => 'ROOT', 'name' => 'Carpeta', 'folder' => ['childCount' => 9], 'parentReference' => ['driveId' => 'DRV1']]);
            }

            return Http::response([
                'value' => [['id' => 'F'.random_int(1, 999999), 'name' => 'a.pdf', 'size' => 1, 'file' => ['mimeType' => 'x']]],
                '@odata.nextLink' => self::GRAPH.'/drives/DRV1/items/ROOT/children?$skiptoken='.random_int(1, 999999),
            ]);
        });

        $remoto = app(MicrosoftGraph::class)->inspeccionar('https://contoso.sharepoint.com/:f:/s/x/y', $cuenta);

        $this->assertSame(MotivoTruncado::Peticiones, $remoto->truncado);
        $this->assertSame(2, $this->peticionesA('/children'));
    }

    public function test_google_drive_stops_after_the_request_cap(): void
    {
        config(['secciones.nube.max_peticiones_listado' => 2]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google);
        Http::fake(fn () => Http::response([
            'files' => [['id' => 'FILE'.random_int(100000, 999999), 'name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => '1', 'md5Checksum' => 'm']],
            'nextPageToken' => 'T'.random_int(1, 999999),
        ]));
        $origen = $this->origenDeNube($cuenta, SeccionOrigen::TIPO_CARPETA, ['remoto_item_id' => 'FOLDER00001']);

        $listado = app(GoogleDrive::class)->listarConResumen($origen);

        $this->assertSame(MotivoTruncado::Peticiones, $listado->truncado);
        Http::assertSentCount(2);
    }

    public function test_dropbox_stops_after_the_request_cap(): void
    {
        config(['secciones.nube.max_peticiones_listado' => 2]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        Http::fake(fn () => Http::response([
            'entries' => [['.tag' => 'file', 'id' => 'id:'.random_int(1, 999999), 'name' => 'a.pdf', 'path_display' => '/a.pdf', 'size' => 1, 'content_hash' => 'h']],
            'has_more' => true,
            'cursor' => 'c',
        ]));
        $origen = $this->origenDeNube($cuenta, SeccionOrigen::TIPO_CARPETA, ['remoto_item_id' => 'id:raiz']);

        $listado = app(Dropbox::class)->listarConResumen($origen);

        $this->assertSame(MotivoTruncado::Peticiones, $listado->truncado);
        Http::assertSentCount(2);
    }

    public function test_a_truncated_listing_never_archives_documents_and_is_recorded(): void
    {
        Storage::fake('local');
        $nube = new ProveedorNubeFalso;
        $this->app->instance(EnlacePublico::class, $nube);
        $this->app->instance(MicrosoftGraph::class, $nube);
        $bytes = (string) file_get_contents($this->excel([['Folio', 'Equipo'], ['A1', 'uno']])->getPathname());
        $origen = SeccionOrigen::forceCreate([
            'seccion_id' => Seccion::factory()->create()->id,
            'tipo' => SeccionOrigen::TIPO_CARPETA,
            'proveedor' => 'microsoft',
            'remoto_item_id' => 'raiz-1',
            'enlace_original' => 'https://1drv.ms/f/s!fake',
            'nombre' => 'Carpeta',
            'incluir_subcarpetas' => true,
            'alimenta_tablas' => false,
            'estado' => EstadoOrigen::Activo,
            'creado_por' => User::factory()->create()->id,
        ]);
        $nube->poner('a', 'A.xlsx', $bytes, 'v1')->poner('b', 'B.xlsx', $bytes.'x', 'v1');
        app(SincronizadorOrigen::class)->sincronizar($origen->fresh());
        $this->assertSame(2, SeccionArchivo::query()->count());

        $nube->quitar('b');
        $nube->truncado = MotivoTruncado::Tiempo;
        app(SincronizadorOrigen::class)->sincronizar($origen->fresh());

        $this->assertSame(2, SeccionArchivo::query()->count(), 'Nada se archiva con un listado truncado.');
        $this->assertSame(0, SeccionArchivo::onlyTrashed()->count());
        $this->assertSame(MotivoTruncado::Tiempo->value, $origen->fresh()->resumen['truncado']);
        $this->assertStringContainsString('tiempo máximo', (string) $origen->fresh()->sincronizacion_error);

        $nube->truncado = null;
        app(SincronizadorOrigen::class)->sincronizar($origen->fresh());

        $this->assertSame(1, SeccionArchivo::query()->count(), 'Con un listado completo sí se archiva.');
        $this->assertNull($origen->fresh()->resumen['truncado']);
    }

    // --- 2. Bearer solo a hosts de API --------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostsNoApi(): array
    {
        return [
            'host de enlaces' => ['https://drive.google.com/x'],
            'sharepoint' => ['https://contoso.sharepoint.com/x'],
            'subdominio de la API' => ['https://evil.graph.microsoft.com/x'],
            'dropbox web' => ['https://www.dropbox.com/x'],
        ];
    }

    #[DataProvider('hostsNoApi')]
    public function test_the_bearer_is_never_sent_to_a_host_outside_the_api_list(string $url): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake();

        try {
            app(ClienteNube::class)->json($cuenta, 'GET', $url);
            $this->fail('Debió rechazar el host.');
        } catch (NubeSyncException) {
            Http::assertNothingSent();
        }
    }

    public function test_download_requires_an_api_host_on_the_first_hop(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake();

        try {
            app(ClienteNube::class)->descargar($cuenta, 'GET', 'https://contoso.sharepoint.com/x', $this->destino);
            $this->fail('Debió rechazar el primer salto.');
        } catch (NubeSyncException) {
            Http::assertNothingSent();
        }
    }

    public function test_download_follows_wide_hosts_on_later_hops_without_the_token(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response('', 302, ['Location' => 'https://contoso.sharepoint.com/pre?sig=1']),
            'contoso.sharepoint.com/*' => Http::response('BYTES', 200),
        ]);

        app(ClienteNube::class)->descargar($cuenta, 'GET', self::GRAPH.'/drives/D/items/I/content', $this->destino);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'sharepoint.com') && ! $r->hasHeader('Authorization'));
    }

    // --- 3. Uso de cuentas por rol (confused deputy) ------------------------

    private function datosVincular(NubeCuenta $cuenta): array
    {
        return ['cuenta_id' => $cuenta->id, 'enlace' => 'https://1drv.ms/f/s!AbCdEfGh123'];
    }

    private function nubeFalsa(): ProveedorNubeFalso
    {
        $nube = new ProveedorNubeFalso;
        $this->app->instance(MicrosoftGraph::class, $nube);
        $this->app->instance(EnlacePublico::class, $nube);

        return $nube;
    }

    public function test_building_staff_cannot_inspect_or_link_with_a_connected_account(): void
    {
        $this->nubeFalsa();
        Queue::fake();
        $seccion = Seccion::factory()->create();
        $cuenta = $this->cuentaDeNube();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->postJson(route('secciones.origenes.inspeccionar', $seccion), $this->datosVincular($cuenta))->assertForbidden();
        $this->postJson(route('secciones.origenes.store', $seccion), $this->datosVincular($cuenta))->assertForbidden();

        $this->assertSame(0, SeccionOrigen::query()->count());
    }

    public function test_building_staff_can_still_link_public_links_without_an_account(): void
    {
        $nube = $this->nubeFalsa();
        $nube->inspeccion = new OrigenRemoto(OrigenRemoto::ARCHIVO, null, 'f1', 'Plan.xlsx');
        Queue::fake();
        $seccion = Seccion::factory()->create();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->postJson(route('secciones.origenes.inspeccionar', $seccion), ['enlace' => 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view'])
            ->assertOk();
    }

    public function test_zone_chief_can_use_an_account_and_the_log_records_which_one(): void
    {
        $this->nubeFalsa();
        Queue::fake();
        $seccion = Seccion::factory()->create();
        $cuenta = $this->cuentaDeNube();
        $jefe = $this->actingAsTwoFactorVerified($this->usuarioConRol('Jefe de Zona'));

        $this->postJson(route('secciones.origenes.store', $seccion), $this->datosVincular($cuenta))->assertCreated();

        $registro = Activity::query()->where('event', 'origen_vinculado')->sole();
        $this->assertSame($jefe->id, $registro->causer_id);
        $this->assertSame($cuenta->id, $registro->properties['cuenta_id']);
        $this->assertSame('microsoft', $registro->properties['cuenta_proveedor']);
        $this->assertStringNotContainsString('token-', (string) json_encode($registro->properties));
    }

    public function test_the_service_itself_refuses_an_account_for_a_user_without_the_ability(): void
    {
        $this->nubeFalsa();
        $seccion = Seccion::factory()->create();
        $cuenta = $this->cuentaDeNube();
        $edificio = $this->usuarioConRol('Edificio');

        $this->expectException(AuthorizationException::class);

        app(OrigenService::class)->vincular($seccion, $cuenta, 'https://1drv.ms/f/s!AbCdEfGh123', null, true, $edificio);
    }

    public function test_the_modal_only_lists_accounts_the_user_may_use(): void
    {
        $seccion = Seccion::factory()->create();
        $this->cuentaDeNube();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->get(route('secciones.show', ['seccion' => $seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertDontSee('persona@ejemplo.test');
    }

    // --- 4. Refresh de tokens ------------------------------------------------

    private function cuentaConCredenciales(): NubeCuenta
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);

        return $this->cuentaDeNube(ProveedorNube::Microsoft, ['expira_en' => now()->subMinute()]);
    }

    public function test_a_definitive_rejection_flags_the_account_for_reconnection(): void
    {
        $cuenta = $this->cuentaConCredenciales();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(CuentaNubeService::class)->refrescar($cuenta);
            $this->fail('Debió fallar.');
        } catch (NubeOAuthException $e) {
            $this->assertSame(CodigoErrorNube::Reconexion, $e->codigo);
        }

        $this->assertSame(EstadoCuentaNube::RequiereReconexion, $cuenta->fresh()->estado);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function fallosPasajeros(): array
    {
        return ['429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('fallosPasajeros')]
    public function test_transient_refresh_failures_do_not_change_the_account_state(int $estado): void
    {
        $cuenta = $this->cuentaConCredenciales();
        Http::fake(['login.microsoftonline.com/*' => Http::response([], $estado)]);

        try {
            app(CuentaNubeService::class)->refrescar($cuenta);
            $this->fail('Debió fallar.');
        } catch (NubeOAuthException $e) {
            $this->assertSame(CodigoErrorNube::Transitorio, $e->codigo);
        }

        $this->assertSame(EstadoCuentaNube::Activa, $cuenta->fresh()->estado);
        $this->assertNull($cuenta->fresh()->ultimo_error);
    }

    public function test_a_refresh_timeout_does_not_change_the_account_state(): void
    {
        $cuenta = $this->cuentaConCredenciales();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        try {
            app(CuentaNubeService::class)->refrescar($cuenta);
            $this->fail('Debió fallar.');
        } catch (NubeOAuthException $e) {
            $this->assertSame(CodigoErrorNube::Transitorio, $e->codigo);
        }

        $this->assertSame(EstadoCuentaNube::Activa, $cuenta->fresh()->estado);
    }

    public function test_refresh_is_serialized_and_rereads_tokens_refreshed_by_another_process(): void
    {
        $cuenta = $this->cuentaConCredenciales();
        $obsoleta = NubeCuenta::query()->findOrFail($cuenta->id);
        $cuenta->forceFill(['access_token' => 'token-ya-renovado', 'expira_en' => now()->addHour()])->save();
        Http::fake();

        app(CuentaNubeService::class)->refrescar($obsoleta);

        Http::assertNothingSent();
        $this->assertSame('token-ya-renovado', $obsoleta->access_token);
        $this->assertTrue(Cache::lock('nube-refresh-'.$cuenta->id, 5)->get(), 'El bloqueo se libera al terminar.');
    }

    public function test_refresh_stores_the_new_tokens_when_nobody_else_refreshed(): void
    {
        $cuenta = $this->cuentaConCredenciales();
        Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'token-nuevo-falso', 'expires_in' => 3600])]);

        app(CuentaNubeService::class)->refrescar($cuenta);

        $this->assertSame('token-nuevo-falso', $cuenta->fresh()->access_token);
        $this->assertSame(1, $this->peticionesA('login.microsoftonline.com'));
    }

    // --- 5. Errores tipados --------------------------------------------------

    /**
     * @return array<string, array{0: int, 1: CodigoErrorNube}>
     */
    public static function estadosHttp(): array
    {
        return [
            '401 tras refresh' => [401, CodigoErrorNube::Reconexion],
            '404' => [404, CodigoErrorNube::OrigenEliminado],
            '410' => [410, CodigoErrorNube::OrigenEliminado],
            '429' => [429, CodigoErrorNube::Transitorio],
            '500' => [500, CodigoErrorNube::Transitorio],
            '503' => [503, CodigoErrorNube::Transitorio],
            '403' => [403, CodigoErrorNube::Otro],
            '400' => [400, CodigoErrorNube::Otro],
        ];
    }

    #[DataProvider('estadosHttp')]
    public function test_the_client_assigns_a_typed_code_from_the_http_status(int $estado, CodigoErrorNube $esperado): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response([], $estado),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-nuevo-falso', 'expires_in' => 3600]),
        ]);
        Sleep::fake();

        try {
            app(ClienteNube::class)->json($cuenta, 'GET', self::GRAPH.'/me/drive');
            $this->fail('Debió fallar.');
        } catch (NubeSyncException $e) {
            $this->assertSame($esperado, $e->codigo);
        }
    }

    public function test_connection_failures_are_typed_as_transient(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake(fn () => throw new ConnectionException('timeout'));

        try {
            app(ClienteNube::class)->json($cuenta, 'GET', self::GRAPH.'/me/drive');
            $this->fail('Debió fallar.');
        } catch (NubeSyncException $e) {
            $this->assertSame(CodigoErrorNube::Transitorio, $e->codigo);
        }
    }

    public function test_size_limits_are_typed_as_limit(): void
    {
        $cuenta = $this->cuentaDeNube();
        config(['secciones.max_archivo_kb' => 1]);
        Http::fake(['graph.microsoft.com/*' => Http::response(str_repeat('A', 5000), 200)]);

        try {
            app(ClienteNube::class)->descargar($cuenta, 'GET', self::GRAPH.'/drives/D/items/I/content', $this->destino);
            $this->fail('Debió fallar.');
        } catch (NubeSyncException $e) {
            $this->assertSame(CodigoErrorNube::Limite, $e->codigo);
        }
    }

    public function test_the_classifier_uses_the_code_and_not_the_message(): void
    {
        $clasificador = app(ClasificadorErrorNube::class);

        $this->assertSame(CodigoErrorNube::Transitorio, $clasificador->clasificar(new NubeSyncException('texto cualquiera', codigo: CodigoErrorNube::Transitorio), null));
        $this->assertSame(CodigoErrorNube::Otro, $clasificador->clasificar(new NubeSyncException(__('No se encontró el elemento en la nube. Revisa que siga compartido con la cuenta conectada.')), null));

        $inactiva = $this->cuentaDeNube(ProveedorNube::Microsoft, ['estado' => EstadoCuentaNube::RequiereReconexion]);
        $this->assertSame(CodigoErrorNube::Reconexion, $clasificador->clasificar(new NubeSyncException('x'), $inactiva));
    }

    // --- 6. Errores de credenciales junto al proveedor correcto --------------

    public function test_each_provider_form_carries_its_hidden_provider_input(): void
    {
        $this->comoAdministrador();

        $html = $this->get(route('nube.cuentas.index'))->assertOk()->getContent();

        foreach (ProveedorNube::cases() as $proveedor) {
            $this->assertStringContainsString('name="_proveedor" value="'.$proveedor->value.'"', $html);
        }
    }

    public function test_validation_errors_show_next_to_the_provider_that_was_submitted(): void
    {
        $this->comoAdministrador();

        $this->from(route('nube.cuentas.index'))
            ->put(route('nube.credenciales.update', 'google'), ['_proveedor' => 'google', 'client_id' => 'con espacios', 'client_secret' => 'x'])
            ->assertRedirect(route('nube.cuentas.index'));

        $html = $this->get(route('nube.cuentas.index'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'El Client ID no debe llevar espacios.'));
        $inicio = (int) strpos($html, 'id="nube-google"');
        $fin = strpos($html, '<section', $inicio);
        $seccionGoogle = $fin === false ? substr($html, $inicio) : substr($html, $inicio, $fin - $inicio);
        $this->assertStringContainsString('El Client ID no debe llevar espacios.', $seccionGoogle);
    }
}
