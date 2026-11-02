<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoOrigen;
use App\Jobs\SincronizarOrigenJob;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Secciones\ArchivoService;
use App\Services\Secciones\NubeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Origen de nube (F1): conversión de los vínculos anteriores, sincronización
 * en cola y reglas del origen. El comportamiento de la sincronización en sí
 * lo cubre SincronizacionNubeTest.
 */
class OrigenesNubeTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const ENLACE_DRIVE = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view?usp=sharing';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function documento(?Seccion $seccion = null): SeccionArchivo
    {
        return app(ArchivoService::class)->guardar(
            $seccion ?? Seccion::factory()->create(),
            $this->excel([['Folio', 'Equipo'], ['A1', 'Escalera 1']]),
            User::factory()->create(),
        );
    }

    private function vinculado(?Seccion $seccion = null): SeccionArchivo
    {
        $archivo = $this->documento($seccion);

        return app(NubeSyncService::class)->vincular($archivo, self::ENLACE_DRIVE, null)->fresh();
    }

    public function test_migration_converts_legacy_links_into_origins_and_is_idempotent(): void
    {
        $activo = $this->documento();
        $pausado = $this->documento();
        $sinVinculo = $this->documento();
        DB::table('seccion_archivos')->where('id', $activo->id)->update([
            'origen_url' => self::ENLACE_DRIVE,
            'origen_proveedor' => 'google',
            'origen_hash' => str_repeat('a', 64),
            'sincronizacion_activa' => true,
            'sincronizado_en' => '2026-10-30 10:00:00',
            'sincronizacion_error' => 'aviso previo',
        ]);
        DB::table('seccion_archivos')->where('id', $pausado->id)->update([
            'origen_url' => self::ENLACE_DRIVE,
            'origen_proveedor' => 'microsoft',
            'origen_hash' => str_repeat('b', 64),
            'sincronizacion_activa' => false,
        ]);
        $migracion = require database_path('migrations/2026_11_03_000100_create_seccion_origenes_table.php');

        $migracion->convertirVinculos();
        $migracion->convertirVinculos();

        $this->assertSame(2, SeccionOrigen::query()->count());
        $origen = $activo->fresh()->origen;
        $this->assertSame('google', $origen->proveedor);
        $this->assertSame(self::ENLACE_DRIVE, $origen->enlace_original);
        $this->assertSame(EstadoOrigen::Activo, $origen->estado);
        $this->assertSame('aviso previo', $origen->sincronizacion_error);
        $this->assertSame('2026-10-30 10:00:00', $origen->sincronizado_en->format('Y-m-d H:i:s'));
        $this->assertSame(str_repeat('a', 64), $activo->fresh()->contenido_hash);
        $this->assertSame(EstadoOrigen::Pausado, $pausado->fresh()->origen->estado);
        $this->assertNull($sinVinculo->fresh()->seccion_origen_id);
        // Las columnas viejas se conservan intactas.
        $this->assertSame(self::ENLACE_DRIVE, DB::table('seccion_archivos')->where('id', $activo->id)->value('origen_url'));
    }

    public function test_linking_an_already_linked_document_reuses_its_origin(): void
    {
        $archivo = $this->vinculado();
        $otroEnlace = 'https://drive.google.com/file/d/ZzYyXxWwVvUuTtSsRr/view';

        app(NubeSyncService::class)->vincular($archivo, $otroEnlace, null);

        $this->assertSame(1, SeccionOrigen::query()->count());
        $this->assertSame($otroEnlace, $archivo->fresh()->origen->enlace_original);
    }

    public function test_unlinking_keeps_the_file_and_relinking_creates_a_new_origin(): void
    {
        $archivo = $this->vinculado();
        $rutaAntes = $archivo->ruta;
        $nube = app(NubeSyncService::class);

        $nube->desvincular($archivo, null);
        $archivo = $archivo->fresh();
        $nube->vincular($archivo, self::ENLACE_DRIVE, null);

        $this->assertSame($rutaAntes, $archivo->fresh()->ruta);
        $this->assertSame(2, SeccionOrigen::withTrashed()->count());
        $this->assertSame(1, SeccionOrigen::query()->count());
        Storage::disk('local')->assertExists($rutaAntes);
    }

    public function test_origin_link_is_hidden_when_serialized(): void
    {
        $origen = $this->vinculado()->origen;

        $this->assertArrayNotHasKey('enlace_original', $origen->toArray());
    }

    public function test_command_queues_a_job_per_active_origin_on_the_nube_queue(): void
    {
        Queue::fake();
        $activo = $this->vinculado();
        $pausado = $this->vinculado();
        $pausado->origen->forceFill(['estado' => EstadoOrigen::Pausado])->save();
        $this->documento();

        $this->artisan('secciones:sincronizar-nube')
            ->expectsOutputToContain('Orígenes enviados a la cola: 1')
            ->assertSuccessful();

        Queue::assertPushed(SincronizarOrigenJob::class, 1);
        Queue::assertPushed(SincronizarOrigenJob::class, fn (SincronizarOrigenJob $job) => $job->origenId === $activo->seccion_origen_id && $job->queue === 'nube');
    }

    public function test_job_is_unique_per_origin(): void
    {
        $this->assertSame('7', (new SincronizarOrigenJob(7))->uniqueId());
        $this->assertSame('8', (new SincronizarOrigenJob(8))->uniqueId());
    }

    public function test_job_syncs_an_active_origin(): void
    {
        $archivo = $this->vinculado();
        Http::fake(['*' => Http::response((string) file_get_contents(app(ArchivoService::class)->rutaAbsoluta($archivo)), 200)]);

        (new SincronizarOrigenJob($archivo->seccion_origen_id))->handle(app(NubeSyncService::class));

        Http::assertSentCount(1);
        $this->assertNotNull($archivo->fresh()->origen->sincronizado_en);
    }

    public function test_job_ignores_paused_or_missing_origins(): void
    {
        Http::fake();
        $archivo = $this->vinculado();
        $archivo->origen->forceFill(['estado' => EstadoOrigen::Pausado])->save();

        (new SincronizarOrigenJob($archivo->seccion_origen_id))->handle(app(NubeSyncService::class));
        (new SincronizarOrigenJob(999999))->handle(app(NubeSyncService::class));

        Http::assertNothingSent();
    }

    public function test_sync_reports_busy_when_the_document_is_already_being_synced(): void
    {
        Http::fake();
        $archivo = $this->vinculado();
        $bloqueo = Cache::lock('nube-sync-'.$archivo->id, 60);
        $this->assertTrue($bloqueo->get());

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);
        $bloqueo->release();

        $this->assertSame(NubeSyncService::OCUPADO, $resultado);
        Http::assertNothingSent();
    }

    public function test_origin_without_document_records_an_error_instead_of_crashing(): void
    {
        $archivo = $this->vinculado();
        $archivo->delete();

        $resultado = app(NubeSyncService::class)->sincronizarOrigen($archivo->origen()->first());

        $this->assertSame(NubeSyncService::ERROR, $resultado);
        $origen = SeccionOrigen::query()->first();
        $this->assertNotNull($origen->sincronizacion_error);
        $this->assertSame(EstadoOrigen::Pausado, $origen->estado);
    }

    public function test_deleting_a_linked_document_retires_its_origin_without_noise_in_the_audit_log(): void
    {
        Queue::fake();
        $archivo = $this->vinculado();

        app(ArchivoService::class)->eliminar($archivo);
        $this->artisan('secciones:sincronizar-nube')->assertSuccessful();

        $this->assertSame(0, SeccionOrigen::query()->count());
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('activity_log', ['event' => 'sincronizacion_fallida']);
    }

    public function test_migration_skips_documents_that_were_already_deleted(): void
    {
        $borrado = $this->documento();
        DB::table('seccion_archivos')->where('id', $borrado->id)->update([
            'origen_url' => self::ENLACE_DRIVE,
            'origen_proveedor' => 'google',
            'sincronizacion_activa' => true,
            'deleted_at' => now(),
        ]);
        $migracion = require database_path('migrations/2026_11_03_000100_create_seccion_origenes_table.php');

        $migracion->convertirVinculos();

        $this->assertSame(0, SeccionOrigen::withTrashed()->count());
    }

    public function test_unlinking_blanks_the_link_stored_in_the_retired_origin(): void
    {
        $archivo = $this->vinculado();
        $origenId = $archivo->seccion_origen_id;

        app(NubeSyncService::class)->desvincular($archivo, null);

        $this->assertSame('', SeccionOrigen::withTrashed()->findOrFail($origenId)->enlace_original);
    }

    public function test_origin_that_does_not_feed_tables_only_replaces_the_file(): void
    {
        $archivo = $this->vinculado();
        $archivo->origen->forceFill(['alimenta_tablas' => false])->save();
        $nueva = (string) file_get_contents($this->excel([['Folio', 'Equipo'], ['A1', 'Escalera 1'], ['A2', 'Nueva']])->getPathname());
        Http::fake(['*' => Http::response($nueva, 200)]);

        $resultado = app(NubeSyncService::class)->sincronizar($archivo);

        $this->assertSame(NubeSyncService::ACTUALIZADO, $resultado);
        $this->assertSame(hash('sha256', $nueva), $archivo->fresh()->contenido_hash);
        $this->assertSame(0, $archivo->seccion->importaciones()->count());
    }

    public function test_public_link_provider_lists_the_single_file_of_an_origin(): void
    {
        $origen = $this->vinculado()->origen;

        $remotos = app(EnlacePublico::class)->listar($origen);

        $this->assertCount(1, $remotos);
        $this->assertInstanceOf(ArchivoRemoto::class, $remotos[0]);
        $this->assertSame($origen->nombre, $remotos[0]->nombre);
        $this->assertNull($remotos[0]->version);
    }

    public function test_cannot_sync_or_unlink_a_document_through_another_section(): void
    {
        $this->comoAdministrador();
        $archivo = $this->vinculado();
        $otraSeccion = Seccion::factory()->create();

        $this->post(route('secciones.archivos.nube.sincronizar', [$otraSeccion, $archivo]))->assertNotFound();
        $this->delete(route('secciones.archivos.nube.desvincular', [$otraSeccion, $archivo]))->assertNotFound();

        $this->assertNotNull($archivo->fresh()->seccion_origen_id);
    }

    public function test_shared_link_is_never_serialized_or_logged(): void
    {
        $archivo = $this->vinculado();
        DB::table('seccion_archivos')->where('id', $archivo->id)->update(['origen_url' => self::ENLACE_DRIVE]);

        $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQ', $archivo->fresh()->toJson());
        $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQ', $archivo->fresh()->origen->toJson());
        $this->assertStringNotContainsString('1AbCdEfGhIjKlMnOpQ', (string) DB::table('activity_log')->pluck('properties')->implode(' '));
    }

    public function test_unlinking_clears_the_legacy_link_column(): void
    {
        $archivo = $this->vinculado();
        DB::table('seccion_archivos')->where('id', $archivo->id)->update(['origen_url' => self::ENLACE_DRIVE]);

        app(NubeSyncService::class)->desvincular($archivo->fresh(), null);

        $this->assertNull(DB::table('seccion_archivos')->where('id', $archivo->id)->value('origen_url'));
    }

    public function test_documents_tab_shows_the_cloud_status_of_a_linked_document(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $archivo = $this->vinculado($seccion);
        $archivo->origen->forceFill(['sincronizado_en' => now(), 'sincronizacion_error' => 'Aviso de prueba'])->save();

        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertSee('Nube (google)', false)
            ->assertSee('Aviso de prueba')
            ->assertSee('Sincronizar ahora')
            ->assertDontSee(self::ENLACE_DRIVE, false);
    }
}
