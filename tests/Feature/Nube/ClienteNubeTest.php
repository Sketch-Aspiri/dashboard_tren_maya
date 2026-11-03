<?php

namespace Tests\Feature\Nube;

use App\Enums\EstadoCuentaNube;
use App\Enums\ProveedorNube;
use App\Services\Nube\ClienteNube;
use App\Services\Secciones\NubeSyncException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\InteractsWithNube;
use Tests\TestCase;

/**
 * ClienteNube: refresh ante 401, Retry-After, hosts permitidos y que el
 * Bearer nunca viaje al host de descarga prefirmada.
 */
class ClienteNubeTest extends TestCase
{
    use InteractsWithNube, RefreshDatabase;

    private const API = 'https://graph.microsoft.com/v1.0/me/drive';

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

    private function cliente(): ClienteNube
    {
        return app(ClienteNube::class);
    }

    public function test_it_sends_the_bearer_token_to_the_api(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::response(['ok' => true])]);

        $datos = $this->cliente()->json($cuenta, 'GET', self::API);

        $this->assertSame(['ok' => true], $datos);
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer token-acceso-falso'));
    }

    public function test_it_refreshes_once_on_401_and_retries_with_the_new_token(): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::sequence()->push([], 401)->push(['ok' => true], 200),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-NUEVO', 'expires_in' => 3600]),
        ]);

        $datos = $this->cliente()->json($cuenta, 'GET', self::API);

        $this->assertSame(['ok' => true], $datos);
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'graph.microsoft.com') && $r->hasHeader('Authorization', 'Bearer token-NUEVO'));
        $this->assertSame('token-NUEVO', $cuenta->fresh()->access_token);
    }

    public function test_a_second_401_is_not_retried_forever(): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response([], 401),
            'login.microsoftonline.com/*' => Http::response(['access_token' => 'token-NUEVO', 'expires_in' => 3600]),
        ]);

        try {
            $this->cliente()->json($cuenta, 'GET', self::API);
            $this->fail('Debió lanzar NubeSyncException.');
        } catch (NubeSyncException $e) {
            $this->assertStringContainsString('rechazó el acceso', $e->getMessage());
            $this->assertStringNotContainsString('token-', $e->getMessage());
        }

        Http::assertSentCount(3); // petición, refresh, reintento
    }

    public function test_a_failed_refresh_flags_the_account_and_raises_a_sync_error(): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response([], 401),
            'login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(NubeSyncException::class);

        try {
            $this->cliente()->json($cuenta, 'GET', self::API);
        } finally {
            $this->assertSame(EstadoCuentaNube::RequiereReconexion, $cuenta->fresh()->estado);
        }
    }

    public function test_an_account_that_needs_reconnection_is_not_used(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft, ['estado' => EstadoCuentaNube::RequiereReconexion]);
        Http::fake();

        $this->expectException(NubeSyncException::class);

        try {
            $this->cliente()->json($cuenta, 'GET', self::API);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_it_waits_for_retry_after_on_429_and_retries(): void
    {
        Sleep::fake();
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '7'])
            ->push(['ok' => true], 200)]);

        $datos = $this->cliente()->json($cuenta, 'GET', self::API);

        $this->assertSame(['ok' => true], $datos);
        Sleep::assertSequence([Sleep::for(7)->seconds()]);
        Http::assertSentCount(2);
    }

    public function test_retry_after_is_capped_and_retries_are_bounded(): void
    {
        Sleep::fake();
        config(['secciones.nube.max_espera_429_segundos' => 5, 'secciones.nube.max_reintentos_429' => 2]);
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::response([], 429, ['Retry-After' => '3600'])]);

        try {
            $this->cliente()->json($cuenta, 'GET', self::API);
            $this->fail('Debió lanzar NubeSyncException.');
        } catch (NubeSyncException $e) {
            $this->assertStringContainsString('limitó las peticiones', $e->getMessage());
        }

        Sleep::assertSequence([Sleep::for(5)->seconds(), Sleep::for(5)->seconds()]);
        Http::assertSentCount(3);
    }

    public function test_it_rejects_an_api_url_on_a_host_that_is_not_allowed(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake();

        try {
            $this->cliente()->json($cuenta, 'GET', 'https://evil.example.com/steal');
            $this->fail('Debió lanzar NubeSyncException.');
        } catch (NubeSyncException) {
            Http::assertNothingSent();
        }
    }

    public function test_it_rejects_plain_http(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake();

        $this->expectException(NubeSyncException::class);

        $this->cliente()->json($cuenta, 'GET', 'http://graph.microsoft.com/v1.0/me');
    }

    public function test_download_follows_the_redirect_without_forwarding_the_bearer(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response('', 302, ['Location' => 'https://contoso.sharepoint.com/_layouts/download?tempauth=abc']),
            'contoso.sharepoint.com/*' => Http::response('CONTENIDO-DEL-ARCHIVO', 200),
        ]);

        $this->cliente()->descargar($cuenta, 'GET', self::API.'/items/X/content', $this->destino);

        $this->assertSame('CONTENIDO-DEL-ARCHIVO', file_get_contents($this->destino));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'graph.microsoft.com') && $r->hasHeader('Authorization', 'Bearer token-acceso-falso'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'contoso.sharepoint.com') && ! $r->hasHeader('Authorization'));
    }

    public function test_download_rejects_a_redirect_to_a_host_that_is_not_allowed(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake([
            'graph.microsoft.com/*' => Http::response('', 302, ['Location' => 'https://evil.example.com/robo']),
            'evil.example.com/*' => Http::response('malicioso', 200),
        ]);

        try {
            $this->cliente()->descargar($cuenta, 'GET', self::API.'/items/X/content', $this->destino);
            $this->fail('Debió lanzar NubeSyncException.');
        } catch (NubeSyncException $e) {
            $this->assertStringContainsString('no permitido', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'evil.example.com'));
    }

    public function test_download_rejects_a_redirect_to_plain_http_or_an_ip(): void
    {
        $cuenta = $this->cuentaDeNube();

        foreach (['http://contoso.sharepoint.com/x', 'https://10.0.0.5/x'] as $destino) {
            Http::fake(['graph.microsoft.com/*' => Http::response('', 302, ['Location' => $destino])]);

            try {
                $this->cliente()->descargar($cuenta, 'GET', self::API.'/items/X/content', $this->destino);
                $this->fail("Debió rechazar {$destino}.");
            } catch (NubeSyncException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_download_stops_after_too_many_redirects(): void
    {
        config(['secciones.nube.max_redirecciones' => 2]);
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::response('', 302, ['Location' => 'https://graph.microsoft.com/v1.0/otra'])]);

        $this->expectException(NubeSyncException::class);
        $this->expectExceptionMessage('demasiadas redirecciones');

        $this->cliente()->descargar($cuenta, 'GET', self::API.'/items/X/content', $this->destino);
    }

    public function test_download_rejects_files_over_the_size_limit(): void
    {
        config(['secciones.max_archivo_kb' => 1]);
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::response(str_repeat('a', 4096), 200)]);

        $this->expectException(NubeSyncException::class);
        $this->expectExceptionMessage('pesa más de');

        $this->cliente()->descargar($cuenta, 'GET', self::API.'/items/X/content', $this->destino);
    }

    public function test_error_responses_never_leak_the_body_or_the_token(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake(['graph.microsoft.com/*' => Http::sequence()
            ->push(['error' => 'SECRETO-CUERPO token-acceso-falso'], 404)
            ->push(['error' => 'SECRETO-CUERPO'], 403)
            ->push(['error' => 'SECRETO-CUERPO'], 500)]);

        foreach ([404, 403, 500] as $codigo) {
            try {
                $this->cliente()->json($cuenta, 'GET', self::API);
                $this->fail('Debió lanzar NubeSyncException.');
            } catch (NubeSyncException $e) {
                $this->assertStringNotContainsString('SECRETO-CUERPO', $e->getMessage());
                $this->assertStringNotContainsString('token-acceso-falso', $e->getMessage());
            }
        }
    }

    public function test_dropbox_style_post_downloads_send_headers_and_an_empty_body(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);
        Http::fake(['content.dropboxapi.com/*' => Http::response('PDF', 200)]);

        $this->cliente()->descargar($cuenta, 'POST', 'https://content.dropboxapi.com/2/sharing/get_shared_link_file', $this->destino, [
            'cabeceras' => ['Dropbox-API-Arg' => '{"url":"x"}'],
        ]);

        $this->assertSame('PDF', file_get_contents($this->destino));
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->hasHeader('Dropbox-API-Arg', '{"url":"x"}') && $r->body() === '');
    }
}
