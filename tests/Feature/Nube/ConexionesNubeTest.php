<?php

namespace Tests\Feature\Nube;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Models\Estacion;
use App\Models\NubeCredencial;
use App\Models\NubeCuenta;
use App\Models\User;
use App\Services\Nube\CredencialesNubeService;
use App\Services\Nube\CuentaNubeService;
use App\Services\Nube\NubeOAuthException;
use App\Services\Nube\OAuthNubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithNube;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * "Conexiones de nube": credenciales cargadas desde la app, OAuth con state
 * de un solo uso + PKCE, cifrado de tokens y que jamás se filtren secretos.
 */
class ConexionesNubeTest extends TestCase
{
    use InteractsWithNube, InteractsWithSecciones, RefreshDatabase;

    private const SECRETO = 'SECRETO-NUBE-QUE-NO-DEBE-SALIR';

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarComoAdmin(ProveedorNube $proveedor, array $datos)
    {
        return $this->put(route('nube.credenciales.update', $proveedor->value), $datos);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesSinAcceso(): array
    {
        return ['Jefe de Zona' => ['Jefe de Zona'], 'Edificio' => ['Edificio'], 'Estación' => ['Estación']];
    }

    // --- Autorización y autenticación --------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('nube.cuentas.index'))->assertRedirect(route('login'));
        $this->put(route('nube.credenciales.update', 'google'), [])->assertRedirect(route('login'));
    }

    #[DataProvider('rolesSinAcceso')]
    public function test_non_admin_roles_get_403_on_every_nube_route(string $rol): void
    {
        $usuario = $this->usuarioConRol($rol);
        if ($rol === 'Estación') {
            $usuario->forceFill(['estacion_id' => Estacion::factory()->create()->id])->save();
        }
        $this->actingAsTwoFactorVerified($usuario);
        $cuenta = $this->cuentaDeNube();

        $this->get(route('nube.cuentas.index'))->assertForbidden();
        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => 'x', 'client_secret' => 'y'])->assertForbidden();
        $this->get(route('nube.conectar', 'microsoft'))->assertForbidden();
        $this->get(route('nube.callback', 'microsoft'))->assertForbidden();
        $this->delete(route('nube.cuentas.destroy', $cuenta))->assertForbidden();

        $this->assertDatabaseCount('nube_credenciales', 0);
        $this->assertNull($cuenta->fresh()->deleted_at);
    }

    public function test_user_without_two_factor_cannot_reach_the_page(): void
    {
        $admin = $this->usuarioConRol('Administrador');
        $admin->forceFill(['google2fa_secret' => 'TESTSECRETKEYFORSPECS123456', 'google2fa_enabled_at' => now()])->save();

        $this->actingAs($admin)->get(route('nube.cuentas.index'))->assertRedirect();
    }

    public function test_admin_sees_a_card_per_provider_with_the_computed_redirect_uri(): void
    {
        $this->comoAdministrador();

        $respuesta = $this->get(route('nube.cuentas.index'))->assertOk();

        foreach (ProveedorNube::cases() as $proveedor) {
            $respuesta->assertSee($proveedor->label());
            $respuesta->assertSee(route('nube.callback', $proveedor->value), false);
        }
        $respuesta->assertSee('Faltan credenciales');
    }

    public function test_page_is_rate_limited(): void
    {
        $this->comoAdministrador();

        for ($i = 0; $i < 20; $i++) {
            $this->get(route('nube.cuentas.index'))->assertOk();
        }

        $this->get(route('nube.cuentas.index'))->assertStatus(429);
    }

    // --- Credenciales ------------------------------------------------------

    public function test_database_credentials_take_priority_over_env(): void
    {
        config([
            'services.google.client_id' => 'env-client-id-0001',
            'services.google.client_secret' => 'env-secret',
        ]);
        $servicio = app(CredencialesNubeService::class);

        $this->assertSame(CredencialesNubeService::ORIGEN_ENV, $servicio->resolver(ProveedorNube::Google)->origen);

        $this->credencialesDeBd(ProveedorNube::Google, 'bd-client-id-9999', 'bd-secret');

        $resuelta = $servicio->resolver(ProveedorNube::Google);
        $this->assertSame(CredencialesNubeService::ORIGEN_APP, $resuelta->origen);
        $this->assertSame('bd-client-id-9999', $resuelta->clientId);
        $this->assertSame('bd-secret', $resuelta->clientSecret);
    }

    public function test_provider_without_credentials_reports_missing(): void
    {
        $estado = app(CredencialesNubeService::class)->estado(ProveedorNube::Dropbox);

        $this->assertFalse($estado['configurado']);
        $this->assertNull(app(CredencialesNubeService::class)->resolver(ProveedorNube::Dropbox));
    }

    public function test_saving_credentials_never_exposes_or_logs_the_secret(): void
    {
        $admin = $this->comoAdministrador();

        $this->guardarComoAdmin(ProveedorNube::Microsoft, [
            'client_id' => 'aaaa-bbbb-cccc-1234',
            'client_secret' => self::SECRETO,
            'tenant' => 'common',
        ])->assertRedirect(route('nube.cuentas.index'));

        $pagina = $this->get(route('nube.cuentas.index'))->assertOk();
        $pagina->assertDontSee(self::SECRETO);
        $pagina->assertSee('Configurado');
        $pagina->assertSee('1234');
        $pagina->assertDontSee('aaaa-bbbb-cccc-1234');

        $registro = Activity::query()->where('event', 'credenciales_nube_actualizadas')->sole();
        $this->assertTrue($registro->causer->is($admin));
        $this->assertStringNotContainsString(self::SECRETO, (string) json_encode($registro->properties));
        $this->assertTrue($registro->properties['secreto_cambiado']);
        $this->assertStringNotContainsString(self::SECRETO, (string) json_encode(Activity::query()->get()->toArray()));
    }

    public function test_secret_and_tokens_are_encrypted_at_rest(): void
    {
        $this->comoAdministrador();
        $this->guardarComoAdmin(ProveedorNube::Dropbox, ['client_id' => 'dbx-id-0001', 'client_secret' => self::SECRETO]);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Dropbox);

        $crudoSecreto = (string) DB::table('nube_credenciales')->value('client_secret');
        $crudaCuenta = DB::table('nube_cuentas')->where('id', $cuenta->id)->first();

        $this->assertNotSame(self::SECRETO, $crudoSecreto);
        $this->assertStringNotContainsString(self::SECRETO, $crudoSecreto);
        $this->assertStringNotContainsString('token-acceso-falso', $crudaCuenta->access_token);
        $this->assertStringNotContainsString('token-refresco-falso', $crudaCuenta->refresh_token);
        $this->assertSame('token-acceso-falso', $cuenta->fresh()->access_token);
        $this->assertArrayNotHasKey('access_token', $cuenta->toArray());
        $this->assertArrayNotHasKey('refresh_token', $cuenta->toArray());
        $this->assertStringNotContainsString(self::SECRETO, (string) json_encode(NubeCredencial::first()));
    }

    public function test_blank_secret_keeps_the_current_one(): void
    {
        $this->comoAdministrador();
        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => 'goo-id-0001', 'client_secret' => self::SECRETO]);

        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => 'goo-id-0002', 'client_secret' => ''])
            ->assertSessionHasNoErrors();

        $resuelta = app(CredencialesNubeService::class)->resolver(ProveedorNube::Google);
        $this->assertSame(self::SECRETO, $resuelta->clientSecret);
        $this->assertSame('goo-id-0002', $resuelta->clientId);
        $this->assertFalse(Activity::query()->where('event', 'credenciales_nube_actualizadas')->latest('id')->first()->properties['secreto_cambiado']);
    }

    public function test_blank_client_id_keeps_the_current_one(): void
    {
        $this->comoAdministrador();
        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => 'goo-id-0001', 'client_secret' => self::SECRETO]);

        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => '', 'client_secret' => ''])->assertSessionHasNoErrors();

        $this->assertSame('goo-id-0001', app(CredencialesNubeService::class)->resolver(ProveedorNube::Google)->clientId);
    }

    public function test_first_save_requires_client_id_and_secret(): void
    {
        $this->comoAdministrador();

        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => '', 'client_secret' => ''])
            ->assertSessionHasErrors(['client_id', 'client_secret']);

        $this->assertDatabaseCount('nube_credenciales', 0);
    }

    public function test_validation_rejects_bad_values_and_does_not_flash_the_secret(): void
    {
        $this->comoAdministrador();

        $respuesta = $this->guardarComoAdmin(ProveedorNube::Microsoft, [
            'client_id' => 'con espacios',
            'client_secret' => self::SECRETO,
            'tenant' => 'mal tenant!',
        ]);

        $respuesta->assertSessionHasErrors(['client_id', 'tenant']);
        $this->assertArrayNotHasKey('client_secret', session()->getOldInput());
        $this->assertDatabaseCount('nube_credenciales', 0);
    }

    public function test_tenant_is_only_stored_for_microsoft(): void
    {
        $this->comoAdministrador();
        $this->guardarComoAdmin(ProveedorNube::Google, ['client_id' => 'goo-id-0001', 'client_secret' => 's', 'tenant' => 'otro']);
        $this->guardarComoAdmin(ProveedorNube::Microsoft, ['client_id' => 'ms-id-0001', 'client_secret' => 's', 'tenant' => 'contoso.onmicrosoft.com']);

        $this->assertNull(NubeCredencial::where('proveedor', 'google')->value('tenant'));
        $this->assertSame('contoso.onmicrosoft.com', app(CredencialesNubeService::class)->resolver(ProveedorNube::Microsoft)->tenant);
    }

    public function test_mass_assignment_ignores_unexpected_fields(): void
    {
        $admin = $this->comoAdministrador();
        $otro = User::factory()->create();

        $this->guardarComoAdmin(ProveedorNube::Google, [
            'client_id' => 'goo-id-0001', 'client_secret' => 's', 'proveedor' => 'dropbox', 'actualizado_por' => $otro->id,
        ]);

        $credencial = NubeCredencial::sole();
        $this->assertSame(ProveedorNube::Google, $credencial->proveedor);
        $this->assertSame($admin->id, $credencial->actualizado_por);
    }

    // --- OAuth -------------------------------------------------------------

    public function test_connect_redirects_to_the_provider_with_pkce_and_a_session_state(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft, 'ms-client-0001');

        $respuesta = $this->get(route('nube.conectar', 'microsoft'));

        $destino = $respuesta->headers->get('Location');
        $this->assertStringStartsWith('https://login.microsoftonline.com/common/oauth2/v2.0/authorize?', $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);
        $this->assertSame('ms-client-0001', $query['client_id']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('offline_access User.Read Files.Read.All Sites.Read.All', $query['scope']);
        $this->assertSame(route('nube.callback', 'microsoft'), $query['redirect_uri']);
        $this->assertSame(session('nube_oauth.state'), $query['state']);
        $this->assertGreaterThanOrEqual(40, strlen($query['state']));
        $this->assertStringNotContainsString('secreto-prueba', $destino);
    }

    public function test_connect_uses_the_configured_microsoft_tenant(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft)->update(['tenant' => 'contoso.onmicrosoft.com']);

        $destino = $this->get(route('nube.conectar', 'microsoft'))->headers->get('Location');

        $this->assertStringStartsWith('https://login.microsoftonline.com/contoso.onmicrosoft.com/oauth2/v2.0/authorize?', $destino);
    }

    public function test_connect_builds_google_and_dropbox_urls_with_offline_access(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Google);
        $this->credencialesDeBd(ProveedorNube::Dropbox);

        $google = (string) $this->get(route('nube.conectar', 'google'))->headers->get('Location');
        parse_str((string) parse_url($google, PHP_URL_QUERY), $g);
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $google);
        $this->assertSame('offline', $g['access_type']);
        $this->assertSame('consent', $g['prompt']);
        $this->assertSame('https://www.googleapis.com/auth/drive.readonly openid email profile', $g['scope']);

        $dropbox = (string) $this->get(route('nube.conectar', 'dropbox'))->headers->get('Location');
        parse_str((string) parse_url($dropbox, PHP_URL_QUERY), $d);
        $this->assertStringStartsWith('https://www.dropbox.com/oauth2/authorize?', $dropbox);
        $this->assertSame('offline', $d['token_access_type']);
        $this->assertSame('files.metadata.read files.content.read sharing.read', $d['scope']);
    }

    public function test_connect_without_credentials_goes_back_with_an_error(): void
    {
        $this->comoAdministrador();

        $this->get(route('nube.conectar', 'google'))
            ->assertRedirect(route('nube.cuentas.index'))
            ->assertSessionHas('error');
    }

    public function test_unknown_provider_is_404(): void
    {
        $this->comoAdministrador();

        $this->get('/nube/yahoo/conectar')->assertNotFound();
    }

    public function test_callback_without_a_pending_state_is_rejected(): void
    {
        $this->comoAdministrador();
        Http::fake();

        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'abc', 'state' => 'inventado']))
            ->assertRedirect(route('nube.cuentas.index'))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseCount('nube_cuentas', 0);
    }

    public function test_callback_with_a_wrong_or_missing_state_is_rejected_and_burns_the_attempt(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        Http::fake();

        $this->get(route('nube.conectar', 'microsoft'));
        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'abc', 'state' => 'otro']))
            ->assertSessionHas('error');
        $this->assertNull(session('nube_oauth'));

        $this->get(route('nube.conectar', 'microsoft'));
        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'abc']))
            ->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertDatabaseCount('nube_cuentas', 0);
    }

    public function test_callback_for_a_different_provider_than_the_pending_one_is_rejected(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $this->credencialesDeBd(ProveedorNube::Google);
        Http::fake();

        $this->get(route('nube.conectar', 'microsoft'));
        $this->get(route('nube.callback', ['proveedor' => 'google', 'code' => 'abc', 'state' => session('nube_oauth.state')]))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_expired_state_is_rejected(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        Http::fake();

        $this->get(route('nube.conectar', 'microsoft'));
        $state = session('nube_oauth.state');
        $this->travel(11)->minutes();

        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'abc', 'state' => $state]))
            ->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_access_denied_shows_a_clear_message(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        Http::fake();

        $this->get(route('nube.conectar', 'microsoft'));
        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'error' => 'access_denied', 'state' => session('nube_oauth.state')]))
            ->assertRedirect(route('nube.cuentas.index'))
            ->assertSessionHas('error', fn (string $m): bool => str_contains($m, 'Se canceló la autorización'));

        Http::assertNothingSent();
        $this->assertDatabaseCount('nube_cuentas', 0);
    }

    public function test_code_exchange_creates_the_account_with_encrypted_tokens_and_logs_it(): void
    {
        $admin = $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft, 'ms-client-0001', 'ms-secret-0001');
        $this->fakeMicrosoftOk('Persona@Contoso.com', 'tok-A', 'ref-A');

        $this->get(route('nube.conectar', 'microsoft'));
        $state = session('nube_oauth.state');
        $verificador = session('nube_oauth.verificador');

        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'codigo-123', 'state' => $state]))
            ->assertRedirect(route('nube.cuentas.index'))
            ->assertSessionHas('status');

        Http::assertSent(function (Request $r): bool {
            return str_contains($r->url(), '/oauth2/v2.0/token')
                && $r['grant_type'] === 'authorization_code'
                && $r['code'] === 'codigo-123'
                && $r['client_secret'] === 'ms-secret-0001'
                && $r['redirect_uri'] === route('nube.callback', 'microsoft');
        });
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'graph.microsoft.com/v1.0/me') && $r->hasHeader('Authorization', 'Bearer tok-A'));

        $cuenta = NubeCuenta::sole();
        $this->assertSame('persona@contoso.com', $cuenta->cuenta_email);
        $this->assertSame('Persona Contoso', $cuenta->cuenta_nombre);
        $this->assertSame(EstadoCuentaNube::Activa, $cuenta->estado);
        $this->assertSame($admin->id, $cuenta->conectada_por);
        $this->assertSame('tok-A', $cuenta->access_token);
        $this->assertSame('ref-A', $cuenta->refresh_token);
        $this->assertNotNull($cuenta->expira_en);

        $crudo = DB::table('nube_cuentas')->first();
        $this->assertStringNotContainsString('tok-A', $crudo->access_token);
        $this->assertStringNotContainsString('ref-A', $crudo->refresh_token);

        $registro = Activity::query()->where('event', 'cuenta_nube_conectada')->sole();
        $propiedades = (string) json_encode($registro->properties);
        $this->assertStringContainsString('persona@contoso.com', $propiedades);
        $this->assertStringNotContainsString('tok-A', $propiedades);
        $this->assertStringNotContainsString('ref-A', $propiedades);
        $this->assertNotSame('', $verificador);
    }

    public function test_pkce_verifier_matches_the_challenge_sent_to_the_provider(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Google);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'g-acceso', 'refresh_token' => 'g-refresh', 'expires_in' => 3600, 'scope' => 'drive.readonly']),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'a@gmail.test', 'name' => 'A']),
        ]);

        $destino = (string) $this->get(route('nube.conectar', 'google'))->headers->get('Location');
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);
        $this->get(route('nube.callback', ['proveedor' => 'google', 'code' => 'c', 'state' => $query['state']]));

        Http::assertSent(function (Request $r) use ($query): bool {
            if (! str_contains($r->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }
            $desafio = rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=');

            return $desafio === $query['code_challenge'];
        });
    }

    public function test_state_is_single_use(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $this->fakeMicrosoftOk('persona@contoso.com', 'tok-A', 'ref-A');

        $this->get(route('nube.conectar', 'microsoft'));
        $parametros = ['proveedor' => 'microsoft', 'code' => 'c', 'state' => session('nube_oauth.state')];

        $this->get(route('nube.callback', $parametros))->assertSessionHas('status');
        $this->get(route('nube.callback', $parametros))->assertSessionHas('error');

        $this->assertDatabaseCount('nube_cuentas', 1);
    }

    public function test_reconnecting_the_same_account_updates_it_and_restores_a_removed_one(): void
    {
        $admin = $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft, ['cuenta_email' => 'persona@contoso.com']);
        app(CuentaNubeService::class)->desconectar($cuenta, $admin);
        $this->fakeMicrosoftOk('persona@contoso.com', 'tok-NUEVO', 'ref-NUEVO');

        $this->get(route('nube.conectar', 'microsoft'));
        $this->get(route('nube.callback', ['proveedor' => 'microsoft', 'code' => 'c', 'state' => session('nube_oauth.state')]));

        $this->assertSame(1, NubeCuenta::withTrashed()->count());
        $actual = NubeCuenta::sole();
        $this->assertSame($cuenta->id, $actual->id);
        $this->assertSame('tok-NUEVO', $actual->access_token);
    }

    public function test_provider_rejecting_the_code_does_not_create_an_account_nor_leak_the_response(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Dropbox);
        Http::fake(['api.dropboxapi.com/*' => Http::response(['error_description' => 'SECRETO-DEL-PROVEEDOR'], 400)]);

        $this->get(route('nube.conectar', 'dropbox'));
        $respuesta = $this->get(route('nube.callback', ['proveedor' => 'dropbox', 'code' => 'c', 'state' => session('nube_oauth.state')]));

        $respuesta->assertSessionHas('error');
        $this->assertStringNotContainsString('SECRETO-DEL-PROVEEDOR', (string) session('error'));
        $this->assertDatabaseCount('nube_cuentas', 0);
    }

    public function test_dropbox_account_profile_is_read(): void
    {
        $this->comoAdministrador();
        $this->credencialesDeBd(ProveedorNube::Dropbox);
        Http::fake([
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'd-acceso', 'refresh_token' => 'd-refresh', 'expires_in' => 14400]),
            'api.dropboxapi.com/2/users/get_current_account' => Http::response(['email' => 'Dbx@Ejemplo.test', 'name' => ['display_name' => 'Dbx Persona']]),
        ]);

        $this->get(route('nube.conectar', 'dropbox'));
        $this->get(route('nube.callback', ['proveedor' => 'dropbox', 'code' => 'c', 'state' => session('nube_oauth.state')]));

        $cuenta = NubeCuenta::sole();
        $this->assertSame('dbx@ejemplo.test', $cuenta->cuenta_email);
        $this->assertSame('Dbx Persona', $cuenta->cuenta_nombre);
    }

    // --- Refresh y desconexión ---------------------------------------------

    public function test_failed_refresh_marks_the_account_as_needing_reconnection(): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft, ['expira_en' => now()->subMinute()]);
        Http::fake(['login.microsoftonline.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'SECRETO-DEL-PROVEEDOR'], 400)]);

        try {
            app(CuentaNubeService::class)->tokenVigente($cuenta);
            $this->fail('Debió fallar el refresh.');
        } catch (NubeOAuthException $e) {
            $this->assertStringNotContainsString('SECRETO-DEL-PROVEEDOR', $e->getMessage());
            $this->assertStringNotContainsString('token-refresco-falso', $e->getMessage());
        }

        $cuenta->refresh();
        $this->assertSame(EstadoCuentaNube::RequiereReconexion, $cuenta->estado);
        $this->assertNotNull($cuenta->ultimo_error);
        $this->assertStringNotContainsString('SECRETO-DEL-PROVEEDOR', $cuenta->ultimo_error);
    }

    public function test_successful_refresh_stores_new_tokens_and_keeps_the_refresh_token_when_omitted(): void
    {
        $this->credencialesDeBd(ProveedorNube::Google);
        $cuenta = $this->cuentaDeNube(ProveedorNube::Google, ['expira_en' => now()->addSeconds(10)]);
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'g-nuevo', 'expires_in' => 3600])]);

        $token = app(CuentaNubeService::class)->tokenVigente($cuenta);

        $this->assertSame('g-nuevo', $token);
        $this->assertSame('token-refresco-falso', $cuenta->fresh()->refresh_token);
        $this->assertTrue($cuenta->fresh()->expira_en->isFuture());
    }

    public function test_a_valid_token_is_not_refreshed(): void
    {
        $cuenta = $this->cuentaDeNube();
        Http::fake();

        $this->assertSame('token-acceso-falso', app(CuentaNubeService::class)->tokenVigente($cuenta));
        Http::assertNothingSent();
    }

    public function test_admin_can_disconnect_an_account_which_clears_tokens_pauses_origins_and_is_logged(): void
    {
        $admin = $this->comoAdministrador();
        $cuenta = $this->cuentaDeNube();
        $origen = $this->origenDeNube($cuenta, 'carpeta');

        $this->delete(route('nube.cuentas.destroy', $cuenta))
            ->assertRedirect(route('nube.cuentas.index'))
            ->assertSessionHas('status');

        $this->assertSoftDeleted('nube_cuentas', ['id' => $cuenta->id]);
        $crudo = DB::table('nube_cuentas')->where('id', $cuenta->id)->first();
        $this->assertNull($crudo->access_token);
        $this->assertNull($crudo->refresh_token);
        $this->assertSame(EstadoOrigen::Pausado, $origen->fresh()->estado);

        $registro = Activity::query()->where('event', 'cuenta_nube_desconectada')->sole();
        $this->assertTrue($registro->causer->is($admin));
        $this->assertStringNotContainsString('token-', (string) json_encode($registro->properties));
    }

    public function test_connected_accounts_are_listed_without_tokens(): void
    {
        $this->comoAdministrador();
        $this->cuentaDeNube(ProveedorNube::Google, ['cuenta_email' => 'lista@ejemplo.test']);

        $this->get(route('nube.cuentas.index'))
            ->assertOk()
            ->assertSee('lista@ejemplo.test')
            ->assertSee('Desconectar')
            ->assertDontSee('token-acceso-falso')
            ->assertDontSee('token-refresco-falso');
    }

    public function test_oauth_service_requires_credentials(): void
    {
        $this->expectException(NubeOAuthException::class);

        app(OAuthNubeService::class)->urlAutorizacion(ProveedorNube::Google);
    }

    public function test_credentials_debug_dump_hides_the_secret(): void
    {
        $this->credencialesDeBd(ProveedorNube::Google, 'cid-1234', self::SECRETO);

        $volcado = print_r(app(CredencialesNubeService::class)->resolver(ProveedorNube::Google), true);

        $this->assertStringNotContainsString(self::SECRETO, $volcado);
    }

    private function fakeMicrosoftOk(string $email, string $acceso, string $refresh): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response(['access_token' => $acceso, 'refresh_token' => $refresh, 'expires_in' => 3599, 'scope' => 'Files.Read.All']),
            'graph.microsoft.com/v1.0/me' => Http::response(['mail' => $email, 'displayName' => 'Persona Contoso']),
        ]);
    }
}
