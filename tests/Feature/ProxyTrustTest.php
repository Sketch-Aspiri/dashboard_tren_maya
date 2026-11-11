<?php

namespace Tests\Feature;

use App\Support\ProxyTrust;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * TRUSTED_PROXIES: por omisión no se confía en ningún proxy, así que una cabecera X-Forwarded-For enviada por el
 * cliente no cambia su IP (límites de intentos, bitácora). Solo si el proxy está configurado se lee la cabecera.
 */
class ProxyTrustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_ip-de-prueba', fn (Request $request) => response()->json(['ip' => $request->ip(), 'seguro' => $request->isSecure()]));
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    private function ip(array $cabeceras = []): array
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/_ip-de-prueba', $cabeceras)->assertOk()->json();
    }

    public function test_by_default_a_forged_forwarded_header_does_not_change_the_client_ip(): void
    {
        ProxyTrust::configure(null);

        $this->assertSame('203.0.113.9', $this->ip(['X-Forwarded-For' => '1.2.3.4', 'X-Forwarded-Proto' => 'https'])['ip']);
        $this->assertFalse($this->ip(['X-Forwarded-Proto' => 'https'])['seguro']);

        ProxyTrust::configure('   ');
        $this->assertSame('203.0.113.9', $this->ip(['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    public function test_the_configured_proxy_is_trusted_but_others_are_not(): void
    {
        ProxyTrust::configure('203.0.113.9, 10.0.0.0/8');

        $this->assertSame('1.2.3.4', $this->ip(['X-Forwarded-For' => '1.2.3.4'])['ip']);
        $this->assertTrue($this->ip(['X-Forwarded-Proto' => 'https'])['seguro']);

        // Un proxy distinto del configurado no es de confianza.
        ProxyTrust::configure('198.51.100.7');
        $this->assertSame('203.0.113.9', $this->ip(['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    public function test_the_wildcard_remains_available_for_development_behind_a_tunnel(): void
    {
        ProxyTrust::configure('*');

        $this->assertSame('1.2.3.4', $this->ip(['X-Forwarded-For' => '1.2.3.4'])['ip']);
    }

    public function test_the_application_reads_the_setting_at_boot_and_the_bootstrap_no_longer_trusts_everything(): void
    {
        $this->assertNull(config('proxies.trusted'));
        $this->assertStringNotContainsString("trustProxies(at: '*')", (string) file_get_contents(base_path('bootstrap/app.php')));
    }
}
