<?php

namespace Tests\Feature\Nube;

use App\Console\Commands\NubeDiagnosticoCommand;
use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Jobs\SincronizarOrigenJob;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithNube;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Página "Estado de sincronización", aviso global por rol y comando de
 * diagnóstico (que nunca imprime secretos).
 */
class EstadoSincronizacionTest extends TestCase
{
    use InteractsWithNube, InteractsWithSecciones, RefreshDatabase;

    private function origen(EstadoOrigen $estado = EstadoOrigen::Activo): SeccionOrigen
    {
        return $this->origenDeNube($this->cuentaDeNube(), 'carpeta', ['nombre' => 'Rol de guardias', 'estado' => $estado->value, 'sincronizado_en' => now()->subMinutes(3)]);
    }

    private function usuarioDe(string $rol): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol($rol));
    }

    public function test_administrator_and_zone_chief_can_see_the_status_page(): void
    {
        $origen = $this->origen();

        foreach (['Administrador', 'Jefe de Zona'] as $rol) {
            $this->usuarioDe($rol);

            $this->get(route('nube.estado'))
                ->assertOk()
                ->assertSee('Estado de sincronización')
                ->assertSee('Rol de guardias')
                ->assertSee($origen->seccion->nombre)
                ->assertSee('persona@ejemplo.test')
                ->assertSee('Sincronizar ahora');
        }
    }

    public function test_building_and_station_users_and_guests_cannot_see_it(): void
    {
        $this->get(route('nube.estado'))->assertRedirect();

        foreach (['Edificio', 'Estación'] as $rol) {
            $this->usuarioDe($rol);
            $this->get(route('nube.estado'))->assertForbidden();
        }
    }

    public function test_the_page_never_shows_tokens_or_payloads(): void
    {
        $this->origen();
        DB::table('failed_jobs')->insert([
            'uuid' => 'abc', 'connection' => 'database', 'queue' => 'nube',
            'payload' => '{"displayName":"App\\\\Jobs\\\\SincronizarOrigenJob","secreto":"PAYLOAD-SECRETO"}',
            'exception' => "RuntimeException: boom con detalle\n#0 /ruta/interna/archivo.php(10)",
            'failed_at' => now(),
        ]);
        $this->usuarioDe('Administrador');

        $this->get(route('nube.estado'))
            ->assertOk()
            ->assertSee('RuntimeException')
            ->assertDontSee('PAYLOAD-SECRETO')
            ->assertDontSee('/ruta/interna')
            ->assertDontSee('token-acceso-falso')
            ->assertDontSee('token-refresco-falso');
    }

    public function test_it_shows_accounts_that_need_reconnection_and_origins_waiting_for_a_mapping(): void
    {
        $this->cuentaDeNube(ProveedorNube::Google, ['cuenta_email' => 'drive@ejemplo.test', 'estado' => EstadoCuentaNube::RequiereReconexion, 'ultimo_error' => 'invalid_grant']);
        $this->origen(EstadoOrigen::PendienteMapeo);
        $this->usuarioDe('Administrador');

        $this->get(route('nube.estado'))
            ->assertSee('drive@ejemplo.test')
            ->assertSee('Requiere reconexión')
            ->assertSee('Falta confirmar el mapeo');
    }

    public function test_the_sync_now_button_queues_the_origin(): void
    {
        Queue::fake();
        $origen = $this->origen();
        $this->usuarioDe('Administrador');

        $this->post(route('secciones.origenes.sincronizar', [$origen->seccion, $origen]))->assertRedirect();

        Queue::assertPushed(SincronizarOrigenJob::class, fn ($job): bool => $job->origenId === $origen->id);
    }

    public function test_the_global_banner_shows_for_allowed_roles_when_something_needs_attention(): void
    {
        $this->cuentaDeNube(ProveedorNube::Microsoft, ['cuenta_email' => 'ms@ejemplo.test', 'estado' => EstadoCuentaNube::RequiereReconexion]);

        foreach (['Administrador', 'Jefe de Zona'] as $rol) {
            $this->usuarioDe($rol);
            $this->get(route('dashboard'))->assertSee('necesita reconectarse')->assertSee('Ver estado de sincronización');
        }
    }

    public function test_the_global_banner_is_hidden_from_building_and_station_roles(): void
    {
        $this->cuentaDeNube(ProveedorNube::Microsoft, ['estado' => EstadoCuentaNube::RequiereReconexion]);

        foreach (['Edificio', 'Estación'] as $rol) {
            $this->usuarioDe($rol);
            $this->get(route('dashboard'))->assertDontSee('necesita reconectarse')->assertDontSee('Ver estado de sincronización');
        }
    }

    public function test_the_banner_also_reports_a_pending_mapping_and_a_held_mass_change_and_disappears_when_clean(): void
    {
        $this->usuarioDe('Administrador');
        $this->get(route('dashboard'))->assertDontSee('Ver estado de sincronización');

        $origen = $this->origen(EstadoOrigen::PendienteMapeo);
        $this->get(route('dashboard'))->assertSee('espera confirmar el mapeo');

        $origen->forceFill(['estado' => EstadoOrigen::Activo])->save();
        $archivo = SeccionArchivo::forceCreate([
            'seccion_id' => $origen->seccion_id, 'seccion_origen_id' => $origen->id, 'nombre_original' => 'a.xlsx',
            'ruta' => 'x/a.xlsx', 'mime' => 'x', 'extension' => 'xlsx', 'tamano' => 1, 'remoto_id' => 'a',
        ]);
        $mapeo = new SeccionMapeo;
        $mapeo->forceFill(['seccion_origen_id' => $origen->id, 'seccion_archivo_id' => $archivo->id, 'hoja' => 'H', 'retenido' => ['motivo' => 'cambio_masivo', 'archivar' => 3, 'total' => 4]])->save();
        $this->get(route('dashboard'))->assertSee('cambio masivo retenido');
        $this->get(route('nube.estado'))->assertSee('Cambio masivo retenido')->assertSee('Aplicar')->assertSee('Ignorar');
    }

    public function test_the_user_menu_links_to_the_status_page_only_for_allowed_roles(): void
    {
        $this->usuarioDe('Administrador');
        $this->get(route('dashboard'))->assertSee(route('nube.estado'), false);

        $this->usuarioDe('Estación');
        $this->get(route('dashboard'))->assertDontSee(route('nube.estado'), false);
    }

    public function test_diagnostic_command_reports_state_without_printing_secrets(): void
    {
        $this->credencialesDeBd(ProveedorNube::Microsoft, 'cid-super-secreto-9999', 'valor-secreto-del-cliente');
        $this->origen();
        Cache::put(NubeDiagnosticoCommand::CLAVE_CRON, now()->timestamp, 3600);
        Cache::put(NubeDiagnosticoCommand::CLAVE_WORKER, now()->subMinute()->timestamp, 3600);

        $this->artisan('nube:diagnostico')
            ->expectsOutputToContain('credenciales: sí')
            ->expectsOutputToContain('credenciales: no')
            ->expectsOutputToContain('activo (último latido')
            ->expectsOutputToContain('último trabajo procesado')
            ->expectsOutputToContain('QUEUE_CONNECTION:')
            ->expectsOutputToContain('Disco libre')
            ->doesntExpectOutputToContain('cid-super-secreto-9999')
            ->doesntExpectOutputToContain('valor-secreto-del-cliente')
            ->doesntExpectOutputToContain('token-acceso-falso')
            ->assertSuccessful();
    }

    public function test_diagnostic_command_warns_when_the_cron_has_never_run(): void
    {
        Cache::forget(NubeDiagnosticoCommand::CLAVE_CRON);

        $this->artisan('nube:diagnostico')->expectsOutputToContain('SIN LATIDO')->assertSuccessful();
    }

    public function test_the_scheduler_registers_the_heartbeat_task(): void
    {
        $tareas = collect(app(Schedule::class)->events())->map(fn ($evento) => $evento->description);

        $this->assertContains('nube-latido', $tareas->all());
    }

    public function test_a_station_user_cannot_dispatch_a_sync(): void
    {
        Queue::fake();
        $origen = $this->origen();
        $this->usuarioDe('Estación');

        $this->post(route('secciones.origenes.sincronizar', [$origen->seccion, $origen]))->assertForbidden();
        Queue::assertNothingPushed();
        $this->assertNotNull(Seccion::query()->find($origen->seccion_id));
    }
}
