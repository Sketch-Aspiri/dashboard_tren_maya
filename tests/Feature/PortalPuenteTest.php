<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Puente con el portal: firma con secreto propio (distinto del de Tickets), catálogo de estaciones, alta de
 * cuentas aprobadas (misma contraseña, vinculada, con rol y estación) y listado sin datos sensibles.
 */
class PortalPuenteTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-portal-a-dashboard-de-prueba-0123456789';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.puente.portal.secreto' => self::SECRETO,
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.ips' => [],
        ]);
    }

    /** @return array<string, string> */
    private function firmado(string $metodo, string $ruta, string $cuerpo = '', array $consulta = [], string $secreto = self::SECRETO, string $sistema = 'portal', ?int $fecha = null): array
    {
        $fecha ??= now()->getTimestamp();
        $nonce = Str::random(40);
        ksort($consulta);
        $canonico = implode("\n", [$metodo, $ruta, http_build_query($consulta, '', '&', PHP_QUERY_RFC3986), $sistema, (string) $fecha, $nonce, hash('sha256', $cuerpo)]);

        return [
            'X-Puente-Sistema' => $sistema,
            'X-Puente-Fecha' => (string) $fecha,
            'X-Puente-Nonce' => $nonce,
            'X-Puente-Firma' => hash_hmac('sha256', $canonico, $secreto),
        ];
    }

    /** GET sin cuerpo, como lo envía el portal (getJson() manda "[]" y cambiaría el hash firmado). */
    private function obtener(string $ruta, array $consulta = [], array $cabeceras = []): TestResponse
    {
        $cabeceras = $cabeceras ?: $this->firmado('GET', $ruta, consulta: $consulta);
        $uri = $ruta.($consulta === [] ? '' : '?'.http_build_query($consulta));

        return $this->call('GET', $uri, [], [], [], $this->transformHeadersToServerVars($cabeceras + ['Accept' => 'application/json']));
    }

    private function crear(array $datos, array $cabeceras = []): TestResponse
    {
        $cuerpo = (string) json_encode($datos);

        return $this->call('POST', '/interno/portal/cuentas', [], [], [], $this->transformHeadersToServerVars(
            ($cabeceras ?: $this->firmado('POST', '/interno/portal/cuentas', $cuerpo)) + ['CONTENT_TYPE' => 'application/json', 'Accept' => 'application/json'],
        ), $cuerpo);
    }

    /** @return array<string, mixed> */
    private function datos(array $cambios = []): array
    {
        return $cambios + [
            'portal_user_id' => (string) Str::uuid(),
            'name' => 'Ana López',
            'email' => 'ana@ejemplo.test',
            'password_hash' => Hash::make('Clave-Segura-2026!', ['rounds' => 10]),
            'role' => 'jefe_zona',
        ];
    }

    public function test_peticiones_sin_firma_valida_del_portal_se_rechazan(): void
    {
        $this->obtener('/interno/portal/catalogo', cabeceras: ['X-Nada' => 'x'])->assertUnauthorized();
        $this->obtener('/interno/portal/catalogo', cabeceras: $this->firmado('GET', '/interno/portal/catalogo', secreto: 'otro-secreto-de-al-menos-treinta-y-dos-caracteres'))->assertUnauthorized();
        $this->obtener('/interno/portal/catalogo', cabeceras: $this->firmado('GET', '/interno/portal/catalogo', sistema: 'tickets'))->assertUnauthorized();
        $this->obtener('/interno/portal/catalogo', cabeceras: $this->firmado('GET', '/interno/portal/catalogo', secreto: 'secreto-tickets-a-dashboard-de-prueba-0123456789'))->assertUnauthorized();
        $this->obtener('/interno/portal/catalogo', cabeceras: $this->firmado('GET', '/interno/portal/catalogo', fecha: now()->subMinutes(30)->getTimestamp()))->assertUnauthorized();
    }

    public function test_el_secreto_del_portal_no_abre_el_canal_de_tickets(): void
    {
        $this->obtener('/interno/contextos/'.str_repeat('a', 40), ['correo' => 'a@ejemplo.test'])->assertUnauthorized();
    }

    public function test_un_nonce_no_se_puede_repetir(): void
    {
        $cabeceras = $this->firmado('GET', '/interno/portal/catalogo');

        $this->obtener('/interno/portal/catalogo', cabeceras: $cabeceras)->assertOk();
        $this->obtener('/interno/portal/catalogo', cabeceras: $cabeceras)->assertUnauthorized();
    }

    public function test_sin_secreto_configurado_las_rutas_del_portal_no_existen(): void
    {
        config(['ecosistema.puente.portal.secreto' => null]);

        $this->obtener('/interno/portal/catalogo')->assertNotFound();
    }

    public function test_el_catalogo_lista_roles_y_estaciones(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);

        $respuesta = $this->obtener('/interno/portal/catalogo')->assertOk();

        $respuesta->assertJsonPath('sistema', 'dashboard')
            ->assertJsonFragment(['clave' => 'estacion', 'nombre' => Roles::ESTACION, 'requiere_estacion' => true])
            ->assertJsonFragment(['id' => $estacion->id, 'nombre' => 'Tulum']);
        $this->assertStringNotContainsString('password', $respuesta->getContent());
    }

    public function test_la_cuenta_se_crea_vinculada_con_la_misma_contrasena_y_su_rol(): void
    {
        $datos = $this->datos();

        $this->crear($datos)->assertOk()->assertJsonPath('estado', 'creada');

        $user = User::where('email', 'ana@ejemplo.test')->firstOrFail();
        $this->assertSame($datos['portal_user_id'], $user->portal_user_id);
        $this->assertNotNull($user->sso_vinculado_at);
        $this->assertTrue($user->hasRole(Roles::JEFE_ZONA));
        $this->assertSame($datos['password_hash'], $user->getRawOriginal('password'));
        $this->assertTrue(Hash::check('Clave-Segura-2026!', $user->password));
        $this->assertDatabaseHas('activity_log', ['event' => 'aprovisionada_por_portal', 'subject_id' => $user->id]);
    }

    public function test_una_cuenta_estacion_lleva_su_estacion_y_las_demas_no(): void
    {
        $estacion = Estacion::factory()->create();

        $this->crear($this->datos(['email' => 'est@ejemplo.test', 'role' => 'estacion', 'estacion_id' => $estacion->id]))->assertOk();
        $this->crear($this->datos(['email' => 'jefe@ejemplo.test', 'role' => 'jefe_zona', 'estacion_id' => $estacion->id]))->assertOk();

        $this->assertSame($estacion->id, User::where('email', 'est@ejemplo.test')->value('estacion_id'));
        $this->assertTrue(User::where('email', 'est@ejemplo.test')->firstOrFail()->hasRole(Roles::ESTACION));
        // El campo no aplica a otros roles: no se guarda aunque llegue.
        $this->assertNull(User::where('email', 'jefe@ejemplo.test')->value('estacion_id'));
    }

    public function test_la_misma_peticion_dos_veces_es_idempotente(): void
    {
        $datos = $this->datos();

        $this->crear($datos)->assertOk()->assertJsonPath('estado', 'creada');
        $this->crear($datos)->assertOk()->assertJsonPath('estado', 'ya_aprovisionada');

        $this->assertSame(1, User::where('email', 'ana@ejemplo.test')->count());
    }

    public function test_una_cuenta_existente_con_ese_correo_nunca_se_toca_ni_se_empareja(): void
    {
        $existente = User::factory()->create(['email' => 'ANA@ejemplo.test']);
        $hashViejo = $existente->getRawOriginal('password');

        $this->crear($this->datos())->assertStatus(409)->assertJsonPath('estado', 'correo_existente');

        $existente->refresh();
        $this->assertNull($existente->portal_user_id);
        $this->assertNull($existente->sso_vinculado_at);
        $this->assertSame($hashViejo, $existente->getRawOriginal('password'));
    }

    public function test_el_cuerpo_se_valida(): void
    {
        $this->crear($this->datos(['role' => 'dios']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->crear($this->datos(['role' => 'estacion']))->assertUnprocessable()->assertJsonValidationErrors('estacion_id');
        $this->crear($this->datos(['role' => 'estacion', 'estacion_id' => 999999]))->assertUnprocessable();
        $this->crear($this->datos(['password_hash' => 'Clave-Segura-2026!']))->assertUnprocessable()->assertJsonValidationErrors('password_hash');
        $this->crear($this->datos(['email' => 'Mayus@Ejemplo.test']))->assertUnprocessable();

        $this->assertSame(0, User::count());
    }

    public function test_un_cuerpo_alterado_no_pasa_la_firma(): void
    {
        $datos = $this->datos();
        $cabeceras = $this->firmado('POST', '/interno/portal/cuentas', (string) json_encode($datos));

        $this->crear(array_replace($datos, ['role' => 'administrador']), $cabeceras)->assertUnauthorized();
        $this->assertSame(0, User::count());
    }

    public function test_el_listado_se_pagina_y_no_trae_datos_sensibles(): void
    {
        User::factory()->create(['email' => 'uno@ejemplo.test', 'sso_vinculado_at' => now()]);
        User::factory()->create(['email' => 'dos@ejemplo.test']);
        User::factory()->create(['email' => 'ia@sistema.invalid']);

        $r = $this->obtener('/interno/portal/cuentas', ['limite' => 1])->assertOk();

        $r->assertJsonCount(1, 'cuentas')->assertJsonPath('cuentas.0.email', 'uno@ejemplo.test')->assertJsonPath('cuentas.0.vinculada', true);
        $this->assertNotNull($r->json('siguiente'));
        $this->assertStringNotContainsString('password', $r->getContent());
        $this->assertStringNotContainsString('google2fa', $r->getContent());

        $siguiente = $this->obtener('/interno/portal/cuentas', ['despues' => $r->json('siguiente'), 'limite' => 500])->assertOk();
        $this->assertSame(['dos@ejemplo.test'], array_column($siguiente->json('cuentas'), 'email'));
        $this->assertNull($siguiente->json('siguiente'));
    }
}
