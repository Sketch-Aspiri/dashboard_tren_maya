<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorIsVerified;
use App\Models\User;
use App\Services\Ecosistema\FirmaEcosistema;
use App\Services\Ecosistema\InicioUnicoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Inicio y cierre de sesión únicos con Tickets: códigos firmados de un solo
 * uso atados al navegador, cuentas vinculadas una sola vez con contraseña,
 * 2FA heredado y sin ciclos de redirección.
 */
class InicioUnicoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const SECRETO = 'secreto-de-prueba-de-al-menos-32-caracteres-xx';

    private const ESTADO = 'EstadoDePrueba0123456789abcdefghijABCDEF';

    private bool $ticketsArriba = true;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.sso.secreto' => self::SECRETO,
            'ecosistema.urls.tickets' => 'https://tickets.ejemplo.test',
            'auth.two_factor.enabled' => true,
        ]);
        Http::fake(['https://tickets.ejemplo.test/up' => fn () => Http::response('', $this->ticketsArriba ? 200 : 500)]);
    }

    private function jefe(string $email = 'jefe@ejemplo.test', bool $vinculado = true, bool $dosPasos = false): User
    {
        $user = User::factory()->create(['email' => $email]);
        $user->assignRole('Jefe de Zona');
        $user->forceFill(array_filter([
            'sso_vinculado_at' => $vinculado ? now() : null,
            'google2fa_secret' => $dosPasos ? 'TESTSECRETKEYFORSPECS123456' : null,
            'google2fa_enabled_at' => $dosPasos ? now() : null,
        ]))->save();

        return $user;
    }

    /**
     * Código como lo firmaría Tickets (mismo formato y secreto).
     */
    private function codigoDeTickets(string $email, bool $mfa = true, string $accion = FirmaEcosistema::ENTRAR, string $aud = 'dashboard', int $exp = 60, ?string $estado = self::ESTADO, bool $vinculada = true): string
    {
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $carga = $b64(json_encode(['v' => 1, 'iss' => 'tickets', 'aud' => $aud, 'act' => $accion, 'email' => $email, 'mfa' => $mfa, 'state' => $estado, 'lnk' => $vinculada, 'nonce' => bin2hex(random_bytes(16)), 'exp' => now()->addSeconds($exp)->getTimestamp()]));

        return $carga.'.'.$b64(hash_hmac('sha256', $carga, self::SECRETO, true));
    }

    /**
     * Llega la respuesta de Tickets al navegador que hizo la pregunta.
     */
    private function entrarCon(string $codigo)
    {
        return $this->withSession([InicioUnicoService::SESION_ESTADO => self::ESTADO])
            ->get(route('ecosistema.entrar', ['codigo' => $codigo]));
    }

    public function test_el_login_pregunta_una_vez_a_tickets_con_un_estado_y_despues_muestra_el_formulario(): void
    {
        $destino = (string) $this->get(route('login'))->assertRedirect()->headers->get('Location');

        $this->assertMatchesRegularExpression('#^https://tickets\.ejemplo\.test/ecosistema/sso\?para=dashboard&estado=[A-Za-z0-9]{40}$#', $destino);
        $this->assertSame(substr($destino, -40), session(InicioUnicoService::SESION_ESTADO));
        $this->get(route('login'))->assertOk()->assertSee('name="password"', false);
        $this->get(route('login', ['local' => 1]))->assertOk();
    }

    public function test_si_tickets_no_responde_o_no_hay_secreto_se_muestra_el_login(): void
    {
        $this->ticketsArriba = false;
        $this->get(route('login'))->assertOk();

        $this->ticketsArriba = true;
        config(['ecosistema.sso.secreto' => null]);
        $this->get(route('login'))->assertOk();
    }

    public function test_una_cuenta_vinculada_entra_y_hereda_el_2fa(): void
    {
        $jefe = $this->jefe(dosPasos: true);

        $this->entrarCon($this->codigoDeTickets('JEFE@ejemplo.test'))->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($jefe);
        $this->assertTrue((bool) session(EnsureTwoFactorIsVerified::SESSION_KEY));
        $this->get(route('dashboard'))->assertOk();
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ecosistema', 'event' => 'sso_login', 'causer_id' => $jefe->id]);
    }

    public function test_sin_2fa_en_el_origen_se_pide_aqui(): void
    {
        $jefe = $this->jefe(dosPasos: true);

        $this->entrarCon($this->codigoDeTickets($jefe->email, mfa: false));

        $this->assertAuthenticatedAs($jefe);
        $this->get(route('dashboard'))->assertRedirect(route('two-factor.verify'));
    }

    public function test_un_codigo_pedido_por_otro_navegador_no_abre_la_sesion(): void
    {
        $this->jefe();

        // Sin estado en la sesión (el enlace llegó de fuera) o con uno distinto.
        $this->get(route('ecosistema.entrar', ['codigo' => $this->codigoDeTickets('jefe@ejemplo.test')]))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->entrarCon($this->codigoDeTickets('jefe@ejemplo.test', estado: str_repeat('z', 40)))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_codigos_alterados_vencidos_reusados_o_de_otra_accion_no_sirven(): void
    {
        $jefe = $this->jefe();
        $bueno = $this->codigoDeTickets($jefe->email);

        foreach ([
            substr($bueno, 0, -2).'xx',
            $this->codigoDeTickets($jefe->email, exp: -5),
            $this->codigoDeTickets($jefe->email, aud: 'tickets'),
            $this->codigoDeTickets($jefe->email, accion: FirmaEcosistema::SALIR),
        ] as $malo) {
            $this->entrarCon($malo)->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->entrarCon($bueno);
        $this->assertAuthenticatedAs($jefe);

        auth()->logout();
        $this->entrarCon($bueno)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /**
     * Cuenta con un 2FA real (se puede generar su código).
     *
     * @return array{0: User, 1: string}
     */
    private function jefeConDosPasos(bool $vinculado = false): array
    {
        $jefe = $this->jefe(vinculado: $vinculado);
        $secreto = (new Google2FA)->generateSecretKey();
        $jefe->forceFill(['google2fa_secret' => $secreto, 'google2fa_enabled_at' => now()])->save();

        return [$jefe, $secreto];
    }

    public function test_la_primera_vez_se_vincula_con_la_contrasena_y_el_2fa_de_aqui_y_despues_ya_no(): void
    {
        [$jefe, $secreto] = $this->jefeConDosPasos();

        $this->entrarCon($this->codigoDeTickets($jefe->email))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();

        // Con la contraseña sola NO se vincula: quien la robó no tiene el 2FA de la persona.
        $this->post(route('login'), ['email' => $jefe->email, 'password' => 'password']);
        $this->assertAuthenticatedAs($jefe);
        $this->assertNull($jefe->fresh()->sso_vinculado_at);

        $this->post(route('two-factor.verify.store'), ['one_time_password' => '000000']);
        $this->assertNull($jefe->fresh()->sso_vinculado_at);

        // Al pasar el reto 2FA, la cuenta queda vinculada.
        $this->post(route('two-factor.verify.store'), ['one_time_password' => (new Google2FA)->getCurrentOtp($secreto)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($jefe->fresh()->sso_vinculado_at);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'ecosistema', 'event' => 'sso_vinculo', 'causer_id' => $jefe->id]);

        auth()->logout();
        $this->entrarCon($this->codigoDeTickets($jefe->email));
        $this->assertAuthenticatedAs($jefe);
    }

    public function test_la_unica_via_a_vincular_sin_el_2fa_es_que_la_cuenta_no_lo_exija(): void
    {
        config(['auth.two_factor.enabled' => false]);
        $jefe = $this->jefe(vinculado: false);

        $this->entrarCon($this->codigoDeTickets($jefe->email));
        $this->post(route('login'), ['email' => $jefe->email, 'password' => 'password']);

        $this->assertNotNull($jefe->fresh()->sso_vinculado_at);
    }

    public function test_un_origen_sin_vinculo_no_abre_sesion_sin_contrasena_aunque_esta_cuenta_este_vinculada(): void
    {
        $jefe = $this->jefe();

        // En Tickets el correo se pudo editar o registrar sin verificar: ese código no basta.
        $this->entrarCon($this->codigoDeTickets($jefe->email, vinculada: false))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();
    }

    public function test_aqui_se_firma_con_el_estado_de_vinculo_de_esta_cuenta(): void
    {
        $firmar = function (User $user): array {
            $this->actingAsTwoFactorVerified($user);
            $destino = (string) $this->get(route('ecosistema.sso', ['para' => 'tickets', 'estado' => self::ESTADO]))->assertRedirect()->headers->get('Location');
            parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);
            [$carga] = explode('.', $query['codigo']);

            return json_decode(base64_decode(strtr($carga, '-_', '+/')), true);
        };

        $this->assertFalse($firmar($this->jefe(vinculado: false))['lnk']);
        $this->assertTrue($firmar($this->jefe('otro@ejemplo.test'))['lnk']);
    }

    public function test_un_codigo_sin_el_claim_de_vinculo_se_trata_como_no_vinculado(): void
    {
        $jefe = $this->jefe();
        $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $carga = $b64(json_encode(['v' => 1, 'iss' => 'tickets', 'aud' => 'dashboard', 'act' => 'entrar', 'email' => $jefe->email, 'mfa' => true, 'state' => self::ESTADO, 'nonce' => bin2hex(random_bytes(16)), 'exp' => now()->addSeconds(60)->getTimestamp()]));

        $this->entrarCon($carga.'.'.$b64(hash_hmac('sha256', $carga, self::SECRETO, true)))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_iniciar_sesion_con_otra_cuenta_no_vincula_la_que_llego_firmada(): void
    {
        $jefe = $this->jefe(vinculado: false);
        $otro = $this->jefe('otro@ejemplo.test', vinculado: false);

        $this->entrarCon($this->codigoDeTickets($jefe->email));
        $this->post(route('login'), ['email' => $otro->email, 'password' => 'password']);

        $this->assertNull($jefe->fresh()->sso_vinculado_at);
        $this->assertNull($otro->fresh()->sso_vinculado_at);
    }

    public function test_cambiar_el_correo_rompe_el_vinculo(): void
    {
        $jefe = $this->jefe();

        $jefe->update(['email' => 'nuevo@ejemplo.test']);

        $this->assertNull($jefe->fresh()->sso_vinculado_at);
    }

    public function test_una_cuenta_que_no_existe_aqui_no_entra_y_se_explica(): void
    {
        $this->entrarCon($this->codigoDeTickets('solo-tickets@ejemplo.test'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status');
        $this->assertGuest();

        User::factory()->create(['email' => 'sin-rol@ejemplo.test', 'sso_vinculado_at' => now()]);
        $this->entrarCon($this->codigoDeTickets('sin-rol@ejemplo.test'));
        $this->assertGuest();
    }

    public function test_aqui_solo_se_comparte_una_sesion_completa(): void
    {
        $this->get(route('ecosistema.sso', ['para' => 'tickets', 'estado' => self::ESTADO]))
            ->assertRedirect('https://tickets.ejemplo.test/ecosistema/entrar?sin_sesion=1');

        // Con contraseña pero sin pasar el 2FA: no se comparte.
        $this->actingAs($this->jefe());
        $this->get(route('ecosistema.sso', ['para' => 'tickets', 'estado' => self::ESTADO]))
            ->assertRedirect('https://tickets.ejemplo.test/ecosistema/entrar?sin_sesion=1');
    }

    public function test_con_sesion_completa_se_responde_con_un_codigo_que_lleva_el_estado(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $destino = (string) $this->get(route('ecosistema.sso', ['para' => 'tickets', 'estado' => self::ESTADO]))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://tickets.ejemplo.test/ecosistema/entrar?codigo=', $destino);
        parse_str((string) parse_url($destino, PHP_URL_QUERY), $query);
        [$carga] = explode('.', $query['codigo']);
        $datos = json_decode(base64_decode(strtr($carga, '-_', '+/')), true);
        $this->assertSame(['tickets', 'entrar', 'jefe@ejemplo.test', true, self::ESTADO, true], [$datos['aud'], $datos['act'], $datos['email'], $datos['mfa'], $datos['state'], $datos['lnk']]);
    }

    public function test_solo_se_responde_a_sistemas_conocidos(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->get(route('ecosistema.sso', ['para' => 'https://malo.test']))->assertSessionHasErrors('para');
    }

    public function test_cerrar_sesion_aqui_la_cierra_en_tickets(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());

        $destino = (string) $this->post(route('logout'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('https://tickets.ejemplo.test/ecosistema/salir?codigo=', $destino);
        $this->assertGuest();
        // Al volver al login no se vuelve a preguntar a Tickets.
        $this->get(route('login'))->assertOk();
    }

    public function test_un_cierre_desde_tickets_cierra_aqui_solo_a_la_misma_persona(): void
    {
        $jefe = $this->jefe();
        $this->actingAsTwoFactorVerified($jefe);

        $this->get(route('ecosistema.salir', ['codigo' => $this->codigoDeTickets('otra@ejemplo.test', accion: FirmaEcosistema::SALIR, estado: null)]))
            ->assertRedirect('https://tickets.ejemplo.test/login');
        $this->assertAuthenticatedAs($jefe);

        $this->get(route('ecosistema.salir', ['codigo' => $this->codigoDeTickets($jefe->email, accion: FirmaEcosistema::SALIR, estado: null)]))
            ->assertRedirect('https://tickets.ejemplo.test/login');
        $this->assertGuest();
    }
}
