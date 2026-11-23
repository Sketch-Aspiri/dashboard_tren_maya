<?php

namespace Tests\Feature;

use App\Jobs\EnviarAvisoPortal;
use App\Jobs\EnviarAvisoTickets;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Services\Ecosistema\FirmaPuente;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Push general por el PWA del portal: con ECOSISTEMA_AVISOS_DESTINO=portal los avisos salen hacia el portal (a las
 * cuentas activas de allá, sin exigir vínculo con Tickets) con la misma firma del puente; con `tickets` todo sigue como
 * antes. El aviso nunca rompe la acción que lo origina.
 */
class EcosistemaAvisosPortalTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO_PORTAL = 'secreto-dashboard-a-portal-de-prueba-0123456789';

    private const RUTA = '/interno/subsistemas/notificaciones';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        config([
            'ecosistema.puente.secreto_entrada' => 'secreto-tickets-a-dashboard-de-prueba-0123456789',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
            'ecosistema.puente.portal.secreto' => self::SECRETO_PORTAL,
            'ecosistema.puente.portal.url' => 'https://portal.ejemplo.test',
            'ecosistema.urls.dashboard' => 'https://dashboard.ejemplo.test',
            'ecosistema.avisos.destino' => 'portal',
        ]);
    }

    private function jefe(string $correo, bool $vinculado = false): User
    {
        $user = User::factory()->create(['email' => $correo, 'sso_vinculado_at' => $vinculado ? now() : null]);
        $user->assignRole('Jefe de Zona');

        return $user;
    }

    private function solicitudEnviada(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::BORRADOR]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'cantidad' => 1, 'precio_unitario' => 10]);

        app(AvisosEcosistema::class)->solicitudEnviada($solicitud);
    }

    public function test_con_destino_portal_el_aviso_va_al_portal_y_no_a_tickets(): void
    {
        Queue::fake();
        $this->jefe('Jefe@Ejemplo.test');

        $this->solicitudEnviada();

        Queue::assertPushed(EnviarAvisoPortal::class, function (EnviarAvisoPortal $job) {
            return $job->correos === ['jefe@ejemplo.test']
                && str_starts_with($job->url, 'https://dashboard.ejemplo.test/')
                && $job->modulo === 'insumos'
                && strlen($job->titulo) <= 60 && strlen($job->cuerpo) <= 120;
        });
        Queue::assertNotPushed(EnviarAvisoTickets::class);
    }

    public function test_el_portal_recibe_a_quien_no_esta_vinculado_a_tickets(): void
    {
        Queue::fake();
        $this->jefe('sin-vinculo@ejemplo.test', vinculado: false);

        $this->solicitudEnviada();

        Queue::assertPushed(EnviarAvisoPortal::class, fn (EnviarAvisoPortal $job) => $job->correos === ['sin-vinculo@ejemplo.test']);
    }

    public function test_con_destino_tickets_todo_sigue_igual(): void
    {
        Queue::fake();
        config(['ecosistema.avisos.destino' => 'tickets', 'ecosistema.puente.url' => 'https://tickets.ejemplo.test']);
        $this->jefe('jefe@ejemplo.test', vinculado: true);

        $this->solicitudEnviada();

        Queue::assertNotPushed(EnviarAvisoPortal::class);
        Queue::assertPushed(EnviarAvisoTickets::class);
    }

    public function test_sin_el_puente_del_portal_configurado_no_se_encola_nada_pero_la_bandeja_local_si_se_guarda(): void
    {
        Queue::fake();
        config(['ecosistema.puente.portal.secreto' => null]);
        $jefe = $this->jefe('jefe@ejemplo.test');

        $this->solicitudEnviada();

        Queue::assertNothingPushed();
        $this->assertSame(1, $jefe->notifications()->count());
    }

    public function test_las_cuentas_tecnicas_no_se_mandan_al_portal(): void
    {
        Queue::fake();
        $this->jefe('asistente-ia@sistema.invalid');
        $this->jefe('persona@ejemplo.test');

        $this->solicitudEnviada();

        Queue::assertPushed(EnviarAvisoPortal::class, fn (EnviarAvisoPortal $job) => $job->correos === ['persona@ejemplo.test']);
    }

    public function test_el_job_firma_el_post_con_el_cuerpo_y_el_secreto_del_portal(): void
    {
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['recipients' => 1])]);

        (new EnviarAvisoPortal(['jefe@ejemplo.test'], 'Título', 'Cuerpo', 'https://dashboard.ejemplo.test/inicio', 'ref-0001', 'asistencia'))
            ->handle(app(FirmaPuente::class));

        Http::assertSent(function (Request $request) {
            $cuerpo = $request->body();
            $datos = $request->data();
            $esperada = hash_hmac('sha256', implode("\n", [
                'POST', self::RUTA, '', 'dashboard', $request->header('X-Puente-Fecha')[0], $request->header('X-Puente-Nonce')[0], hash('sha256', $cuerpo),
            ]), self::SECRETO_PORTAL);

            return $request->url() === 'https://portal.ejemplo.test'.self::RUTA
                && $request->method() === 'POST'
                && $request->header('X-Puente-Sistema')[0] === 'dashboard'
                && hash_equals($esperada, $request->header('X-Puente-Firma')[0])
                && $datos['correos'] === ['jefe@ejemplo.test'] && $datos['ref'] === 'ref-0001' && $datos['modulo'] === 'asistencia';
        });
    }

    public function test_si_el_portal_no_acepta_el_aviso_el_job_falla_para_reintentarse(): void
    {
        Http::fake(['https://portal.ejemplo.test/*' => Http::response(['error' => 'unauthorized'], 401)]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 401');

        (new EnviarAvisoPortal(['jefe@ejemplo.test'], 'T', 'C', 'https://dashboard.ejemplo.test/inicio', 'ref-0002'))
            ->handle(app(FirmaPuente::class));
    }

    public function test_si_el_portal_no_responde_el_job_falla_sin_revelar_detalles(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error con secreto-dashboard-a-portal'));

        try {
            (new EnviarAvisoPortal(['jefe@ejemplo.test'], 'T', 'C', 'https://dashboard.ejemplo.test/inicio', 'ref-0003'))
                ->handle(app(FirmaPuente::class));
            $this->fail('Debió lanzar RuntimeException.');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('secreto', $e->getMessage());
        }
    }

    public function test_el_job_reintenta_con_espera_creciente_y_mismo_ref(): void
    {
        $job = new EnviarAvisoPortal(['a@ejemplo.test'], 'T', 'C', 'https://dashboard.ejemplo.test/inicio', 'ref-0004');

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 600], $job->backoff());
        $this->assertSame('ref-0004', $job->ref);
    }
}
