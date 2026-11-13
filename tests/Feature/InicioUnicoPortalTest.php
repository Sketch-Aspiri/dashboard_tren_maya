<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ecosistema\FirmaEcosistema;
use App\Services\Ecosistema\InicioUnicoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * El portal (zonaorientetm.tech) emite el inicio y cierre de sesión únicos con los mismos códigos firmados que
 * se usaban entre el Dashboard y Tickets. Si ECOSISTEMA_PORTAL_URL está definido, el portal es el origen.
 */
class InicioUnicoPortalTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const SECRETO = 'secreto-de-prueba-de-al-menos-32-caracteres-xx';

    private const ESTADO = 'EstadoDePrueba0123456789abcdefghijABCDEF';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.sso.secreto' => self::SECRETO,
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'ecosistema.urls.portal' => 'https://portal.ejemplo.test',
            'auth.two_factor.enabled' => true,
        ]);
        Http::fake(['https://portal.ejemplo.test/up' => Http::response('', 200)]);
    }

    private function jefe(bool $vinculado = true): User
    {
        $user = User::factory()->create(['email' => 'jefe@ejemplo.test']);
        $user->assignRole('Jefe de Zona');
        $user->forceFill(['sso_vinculado_at' => $vinculado ? now() : null])->save();

        return $user;
    }

    /** Código como lo firma el portal (mismo formato y secreto). */
    private function codigoDelPortal(string $email, string $accion = FirmaEcosistema::ENTRAR, bool $mfa = true): string
    {
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $carga = $b64(json_encode([
            'v' => 1, 'iss' => 'portal', 'aud' => 'dashboard', 'act' => $accion, 'email' => $email, 'mfa' => $mfa,
            'state' => self::ESTADO, 'lnk' => true, 'nonce' => bin2hex(random_bytes(16)),
            'exp' => now()->addSeconds(60)->getTimestamp(),
        ]));

        return $carga.'.'.$b64(hash_hmac('sha256', $carga, self::SECRETO, true));
    }

    public function test_el_login_le_pregunta_al_portal_y_no_a_tickets_cuando_esta_configurado(): void
    {
        $destino = (string) $this->get(route('login'))->assertRedirect()->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://portal\.ejemplo\.test/ecosistema/sso\?para=dashboard&estado=[A-Za-z0-9]{40}$#', $destino);
    }

    public function test_un_codigo_del_portal_abre_la_sesion_de_una_cuenta_vinculada(): void
    {
        $jefe = $this->jefe();

        $this->withSession([InicioUnicoService::SESION_ESTADO => self::ESTADO])
            ->get(route('ecosistema.entrar', ['codigo' => $this->codigoDelPortal('JEFE@ejemplo.test')]))
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($jefe);
    }

    public function test_un_codigo_del_portal_no_abre_una_cuenta_sin_vincular_aqui(): void
    {
        $this->jefe(vinculado: false);

        $this->withSession([InicioUnicoService::SESION_ESTADO => self::ESTADO])
            ->get(route('ecosistema.entrar', ['codigo' => $this->codigoDelPortal('jefe@ejemplo.test')]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_el_cierre_del_portal_cierra_la_sesion_y_vuelve_a_su_login(): void
    {
        $jefe = $this->jefe();

        $this->actingAs($jefe)
            ->get(route('ecosistema.salir', ['codigo' => $this->codigoDelPortal('jefe@ejemplo.test', FirmaEcosistema::SALIR, false)]))
            ->assertRedirect('https://portal.ejemplo.test/login');

        $this->assertGuest();
    }

    public function test_sin_portal_configurado_el_origen_sigue_siendo_tickets(): void
    {
        config(['ecosistema.urls' => [
            'dashboard' => 'https://dashboard.ejemplo.test',
            'tickets' => 'https://tickets.ejemplo.test',
        ]]);

        $this->assertSame('tickets', app(InicioUnicoService::class)->otroSistema());
    }
}
