<?php

namespace Tests\Feature\Nube;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Jobs\SincronizarOrigenJob;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\AcumuladorListado;
use App\Services\Nube\Datos\ArchivoRemoto;
use App\Services\Nube\NubeTransitoriaException;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\MicrosoftGraph;
use App\Services\Secciones\NubeSyncException;
use App\Services\Secciones\NubeSyncService;
use App\Services\Secciones\OrigenService;
use App\Services\Secciones\SincronizadorOrigen;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithNube;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Concerns\ProveedorNubeFalso;
use Tests\TestCase;

/**
 * Motor de sincronización de carpetas (CalculadorDiferencias + job) con una
 * nube de mentira: altas, cambios, renombres, bajas, restauraciones, omitidos
 * y los fallos que no deben reintentarse sin fin.
 */
class SincronizacionCarpetasTest extends TestCase
{
    use InteractsWithNube, InteractsWithSecciones, RefreshDatabase;

    private ProveedorNubeFalso $nube;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->nube = new ProveedorNubeFalso;
        $this->app->instance(EnlacePublico::class, $this->nube);
        $this->app->instance(MicrosoftGraph::class, $this->nube);
    }

    private function bytes(string $valor): string
    {
        return (string) file_get_contents($this->excel([['Folio', 'Equipo'], ['A1', $valor]])->getPathname());
    }

    private function origen(?int $cuentaId = null): SeccionOrigen
    {
        return SeccionOrigen::forceCreate([
            'seccion_id' => Seccion::factory()->create()->id,
            'nube_cuenta_id' => $cuentaId,
            'tipo' => SeccionOrigen::TIPO_CARPETA,
            'proveedor' => 'microsoft',
            'remoto_item_id' => 'raiz-1',
            'enlace_original' => 'https://1drv.ms/f/s!fake',
            'nombre' => 'Carpeta de prueba',
            'incluir_subcarpetas' => true,
            'alimenta_tablas' => false,
            'estado' => EstadoOrigen::Activo,
            'creado_por' => User::factory()->create()->id,
        ]);
    }

    private function sincronizar(SeccionOrigen $origen): string
    {
        return app(SincronizadorOrigen::class)->sincronizar($origen->fresh());
    }

    private function conTresArchivos(): SeccionOrigen
    {
        $this->nube
            ->poner('a', 'Raiz.xlsx', $this->bytes('uno'), 'v1')
            ->poner('b', 'Sub/Dos.xlsx', $this->bytes('dos'), 'v1')
            ->poner('c', 'Sub/Hondo/Tres.xlsx', $this->bytes('tres'), 'v1');

        $origen = $this->origen();
        $this->sincronizar($origen);

        return $origen;
    }

    public function test_first_sync_creates_a_document_per_remote_file_keeping_the_folder_path(): void
    {
        $origen = $this->conTresArchivos();

        $documentos = SeccionArchivo::query()->where('seccion_origen_id', $origen->id)->orderBy('remoto_id')->get();
        $this->assertCount(3, $documentos);
        $this->assertSame(['Raiz.xlsx', 'Sub/Dos.xlsx', 'Sub/Hondo/Tres.xlsx'], $documentos->pluck('remoto_ruta')->all());
        $this->assertSame('Tres.xlsx', $documentos[2]->nombre_original);
        $this->assertNull($documentos[0]->estacion_id);
        $this->assertSame('v1', $documentos[0]->remoto_version);
        $this->assertNotNull($documentos[0]->contenido_hash);
        Storage::disk('local')->assertExists($documentos[0]->ruta);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_agregado_nube', 'subject_id' => $documentos[0]->id]);

        $origen->refresh();
        $this->assertNotNull($origen->sincronizado_en);
        $this->assertNull($origen->sincronizacion_error);
        $this->assertSame(3, $origen->resumen['archivos']);
        $this->assertSame(3, $origen->resumen['ciclo']['altas']);
    }

    public function test_second_sync_without_changes_downloads_nothing(): void
    {
        $origen = $this->conTresArchivos();
        $descargas = $this->nube->descargas;

        $this->sincronizar($origen);

        $this->assertSame($descargas, $this->nube->descargas);
        $this->assertSame(3, SeccionArchivo::query()->count());
    }

    public function test_content_change_replaces_the_file_without_duplicating(): void
    {
        $origen = $this->conTresArchivos();
        $antes = SeccionArchivo::query()->where('remoto_id', 'b')->firstOrFail();
        $this->nube->poner('b', 'Sub/Dos.xlsx', $this->bytes('dos-cambiado'), 'v2');

        $this->sincronizar($origen);

        $this->assertSame(3, SeccionArchivo::query()->count());
        $despues = $antes->fresh();
        $this->assertSame('v2', $despues->remoto_version);
        $this->assertNotSame($antes->contenido_hash, $despues->contenido_hash);
        $this->assertNotSame($antes->ruta, $despues->ruta);
        Storage::disk('local')->assertMissing($antes->ruta);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_sincronizado', 'subject_id' => $antes->id]);
    }

    public function test_same_content_with_a_new_version_token_is_not_replaced(): void
    {
        $origen = $this->conTresArchivos();
        $antes = SeccionArchivo::query()->where('remoto_id', 'a')->firstOrFail();
        $this->nube->poner('a', 'Raiz.xlsx', $this->nube->contenidos['a'], 'v9');

        $this->sincronizar($origen);

        $despues = $antes->fresh();
        $this->assertSame('v9', $despues->remoto_version);
        $this->assertSame($antes->ruta, $despues->ruta);
        $this->assertDatabaseMissing('activity_log', ['event' => 'documento_sincronizado', 'subject_id' => $antes->id]);
    }

    public function test_rename_or_move_updates_the_record_without_downloading(): void
    {
        $origen = $this->conTresArchivos();
        $descargas = $this->nube->descargas;
        $this->nube->poner('b', 'Otra/Renombrado.xlsx', $this->bytes('dos'), 'v1');

        $this->sincronizar($origen);

        $this->assertSame($descargas, $this->nube->descargas);
        $documento = SeccionArchivo::query()->where('remoto_id', 'b')->firstOrFail();
        $this->assertSame('Renombrado.xlsx', $documento->nombre_original);
        $this->assertSame('Otra/Renombrado.xlsx', $documento->remoto_ruta);
        $this->assertSame(3, SeccionArchivo::query()->count());
    }

    public function test_file_removed_from_the_cloud_is_archived_logged_and_restorable(): void
    {
        $origen = $this->conTresArchivos();
        $baja = SeccionArchivo::query()->where('remoto_id', 'c')->firstOrFail();
        $this->nube->quitar('c');

        $this->sincronizar($origen);

        $this->assertSoftDeleted('seccion_archivos', ['id' => $baja->id]);
        Storage::disk('local')->assertExists($baja->ruta);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_archivado_nube', 'subject_id' => $baja->id]);
        $this->assertSame(2, $origen->fresh()->resumen['archivos']);

        $this->nube->poner('c', 'Sub/Hondo/Tres.xlsx', $this->bytes('tres'), 'v1');
        $this->sincronizar($origen);

        $this->assertNotSoftDeleted('seccion_archivos', ['id' => $baja->id]);
        $this->assertSame(3, SeccionArchivo::query()->count());
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_restaurado_nube', 'subject_id' => $baja->id]);
    }

    public function test_skipped_files_are_counted_with_their_reason(): void
    {
        $acumulador = new AcumuladorListado;
        $acumulador->agregar(new ArchivoRemoto('ok', 'Bien.xlsx', 'Bien.xlsx', 100, 'v1'));
        $acumulador->agregar(new ArchivoRemoto('mal', 'Programa.exe', 'Programa.exe', 100, 'v1'));
        $acumulador->agregar(new ArchivoRemoto('zip', 'Todo.zip', 'Todo.zip', 100, 'v1'));
        $listado = $acumulador->resultado();
        $this->nube->archivos = $listado->archivos;
        $this->nube->omitidos = $listado->omitidos;
        $this->nube->contenidos['ok'] = $this->bytes('x');
        $origen = $this->origen();

        $this->sincronizar($origen);

        $resumen = $origen->fresh()->resumen;
        $this->assertSame(1, $resumen['archivos']);
        $this->assertSame(['extension_no_permitida' => 1, 'zip' => 1], $resumen['omitidos']);
    }

    public function test_file_over_the_size_limit_is_skipped_and_never_downloaded(): void
    {
        $acumulador = new AcumuladorListado;
        $acumulador->agregar(new ArchivoRemoto('grande', 'Enorme.xlsx', 'Enorme.xlsx', 101 * 1024 * 1024, 'v1'));
        $listado = $acumulador->resultado();
        $this->nube->archivos = $listado->archivos;
        $this->nube->omitidos = $listado->omitidos;
        $origen = $this->origen();

        $this->sincronizar($origen);

        $this->assertSame(0, $this->nube->descargas);
        $this->assertSame(0, SeccionArchivo::query()->count());
        $this->assertSame(['mayor_al_limite' => 1], $origen->fresh()->resumen['omitidos']);
    }

    public function test_download_that_is_not_a_real_document_is_skipped(): void
    {
        $this->nube->poner('x', 'Falso.xlsx', '<html>Inicia sesión</html>', 'v1');
        $origen = $this->origen();

        $this->sincronizar($origen);

        $this->assertSame(0, SeccionArchivo::query()->count());
        $this->assertSame(['contenido_no_valido' => 1], $origen->fresh()->resumen['omitidos']);
    }

    public function test_one_failing_download_does_not_stop_the_other_files(): void
    {
        $this->nube->poner('a', 'Uno.xlsx', $this->bytes('uno'), 'v1')->poner('b', 'Dos.xlsx', $this->bytes('dos'), 'v1');
        $this->nube->falloAlDescargar['a'] = new NubeSyncException(__('La cuenta conectada no tiene permiso para ver ese elemento.'));
        $origen = $this->origen();

        $this->sincronizar($origen);

        $this->assertSame(1, SeccionArchivo::query()->count());
        $this->assertSame(['error_de_descarga' => 1], $origen->fresh()->resumen['omitidos']);
        $this->assertNotNull($origen->fresh()->sincronizacion_error);
    }

    public function test_account_that_needs_reconnection_leaves_the_origin_waiting_without_calling_the_cloud(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft, ['estado' => EstadoCuentaNube::RequiereReconexion]);
        $origen = $this->origen($cuenta->id);

        $resultado = $this->sincronizar($origen);

        $this->assertSame(SincronizadorOrigen::RECONEXION, $resultado);
        $this->assertSame(0, $this->nube->listados);
        $this->assertSame(EstadoOrigen::RequiereReconexion, $origen->fresh()->estado);
        $this->assertNotNull($origen->fresh()->sincronizacion_error);
        $this->assertDatabaseHas('activity_log', ['event' => 'sincronizacion_fallida', 'subject_id' => $origen->id]);
    }

    public function test_origin_waiting_for_reconnection_is_not_dispatched_until_the_account_is_active_again(): void
    {
        $cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft, ['estado' => EstadoCuentaNube::RequiereReconexion]);
        $origen = $this->origen($cuenta->id);
        $origen->forceFill(['estado' => EstadoOrigen::RequiereReconexion])->save();
        $this->nube->poner('a', 'Uno.xlsx', $this->bytes('uno'), 'v1');

        $this->artisan('secciones:sincronizar-nube', ['--ahora' => true])->assertSuccessful();
        $this->assertSame(0, SeccionArchivo::query()->count());

        $cuenta->forceFill(['estado' => EstadoCuentaNube::Activa])->save();
        $this->artisan('secciones:sincronizar-nube', ['--ahora' => true])->assertSuccessful();

        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
    }

    public function test_cloud_rejecting_the_account_marks_the_origin_as_needing_reconnection(): void
    {
        $this->nube->falloAlListar = new NubeSyncException(__('La nube rechazó el acceso de la cuenta. Reconéctala desde Conexiones de nube.'));
        $origen = $this->origen();

        $resultado = $this->sincronizar($origen);

        $this->assertSame(SincronizadorOrigen::RECONEXION, $resultado);
        $this->assertSame(EstadoOrigen::RequiereReconexion, $origen->fresh()->estado);
    }

    public function test_folder_deleted_in_the_cloud_marks_the_origin_and_keeps_the_documents(): void
    {
        $origen = $this->conTresArchivos();
        $this->nube->falloAlListar = new NubeSyncException(__('No se encontró el elemento en la nube. Revisa que siga compartido con la cuenta conectada.'));

        $resultado = $this->sincronizar($origen);

        $this->assertSame(SincronizadorOrigen::ELIMINADO, $resultado);
        $this->assertSame(EstadoOrigen::OrigenEliminado, $origen->fresh()->estado);
        $this->assertSame(3, SeccionArchivo::query()->count());
    }

    public function test_temporary_cloud_errors_are_retried_by_the_job_and_recorded_when_it_gives_up(): void
    {
        $origen = $this->conTresArchivos();
        $this->nube->falloAlListar = new NubeSyncException('La nube respondió con error 503.');
        $job = new SincronizarOrigenJob($origen->id);

        try {
            $job->handle(app(NubeSyncService::class));
            $this->fail('El fallo pasajero debe subir para que la cola reintente.');
        } catch (NubeTransitoriaException $e) {
            $job->failed($e);
        }

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff);
        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertSame('La nube respondió con error 503.', $origen->fresh()->sincronizacion_error);
        $this->assertDatabaseHas('activity_log', ['event' => 'sincronizacion_fallida', 'subject_id' => $origen->id]);
    }

    public function test_unexpected_exception_message_never_reaches_the_origin_or_the_log(): void
    {
        $origen = $this->conTresArchivos();
        $job = new SincronizarOrigenJob($origen->id);

        $job->failed(new RuntimeException('SQLSTATE secreto token=abc123'));

        $this->assertStringNotContainsString('abc123', (string) $origen->fresh()->sincronizacion_error);
        $this->assertStringNotContainsString('abc123', (string) json_encode(Activity::query()->get()->toArray()));
    }

    public function test_not_enough_free_disk_aborts_with_a_readable_error_and_downloads_nothing(): void
    {
        config(['secciones.nube.reserva_disco_gb' => 1.0E+12]);
        $this->nube->poner('a', 'Uno.xlsx', $this->bytes('uno'), 'v1');
        $origen = $this->origen();

        $resultado = $this->sincronizar($origen);

        $this->assertSame(SincronizadorOrigen::ERROR, $resultado);
        $this->assertSame(0, $this->nube->descargas);
        $this->assertSame(0, SeccionArchivo::query()->count());
        $this->assertStringContainsString('Espacio en disco insuficiente', (string) $origen->fresh()->sincronizacion_error);
        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertDatabaseHas('activity_log', ['event' => 'sincronizacion_fallida', 'subject_id' => $origen->id]);
    }

    public function test_empty_listing_after_having_documents_is_held_back_until_confirmed(): void
    {
        $origen = $this->conTresArchivos();
        $this->nube->archivos = [];

        $resultado = $this->sincronizar($origen);

        $this->assertSame(SincronizadorOrigen::RETENIDO, $resultado);
        $this->assertSame(3, SeccionArchivo::query()->count());
        $this->assertTrue($origen->fresh()->resumen['cambio_masivo_retenido']);
        $this->assertStringContainsString('Cambio masivo retenido', (string) $origen->fresh()->sincronizacion_error);

        app(OrigenService::class)->actualizar($origen->fresh()->load('cuenta'), ['confirmar_baja_masiva' => true], User::factory()->create());
        $this->sincronizar($origen);

        $this->assertSame(0, SeccionArchivo::query()->count());
        $this->assertSame(3, SeccionArchivo::onlyTrashed()->count());
        $this->assertFalse($origen->fresh()->resumen['cambio_masivo_retenido']);
    }

    public function test_command_runs_one_origin_in_process_with_the_now_option(): void
    {
        $this->nube->poner('a', 'Uno.xlsx', $this->bytes('uno'), 'v1');
        $origen = $this->origen();
        $otro = $this->origen();

        $this->artisan('secciones:sincronizar-nube', ['--origen' => $origen->id, '--ahora' => true])->assertSuccessful();

        $this->assertSame(1, SeccionArchivo::query()->where('seccion_origen_id', $origen->id)->count());
        $this->assertSame(0, SeccionArchivo::query()->where('seccion_origen_id', $otro->id)->count());
    }

    public function test_command_queues_a_job_per_active_origin_on_the_cloud_queue(): void
    {
        Queue::fake();
        $activo = $this->origen();
        $pausado = $this->origen();
        $pausado->forceFill(['estado' => EstadoOrigen::Pausado])->save();

        $this->artisan('secciones:sincronizar-nube')->assertSuccessful();

        Queue::assertPushed(SincronizarOrigenJob::class, 1);
        Queue::assertPushed(SincronizarOrigenJob::class, fn (SincronizarOrigenJob $job) => $job->origenId === $activo->id && $job->queue === 'nube');
    }

    public function test_job_is_unique_per_origin_and_timeout_is_below_the_queue_retry_after(): void
    {
        $job = new SincronizarOrigenJob(5);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('5', $job->uniqueId());
        $this->assertLessThan((int) config('queue.connections.database.retry_after'), $job->timeout);
    }

    public function test_sync_events_never_store_links_or_tokens(): void
    {
        $this->conTresArchivos();

        $registros = (string) json_encode(Activity::query()->get()->toArray());

        $this->assertStringNotContainsString('1drv.ms', $registros);
        $this->assertStringNotContainsString('token', strtolower($registros));
    }
}
