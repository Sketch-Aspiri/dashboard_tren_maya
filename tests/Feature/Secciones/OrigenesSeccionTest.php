<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoCuentaNube;
use App\Enums\EstadoOrigen;
use App\Enums\ProveedorNube;
use App\Jobs\SincronizarOrigenJob;
use App\Models\Estacion;
use App\Models\NubeCuenta;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\MicrosoftGraph;
use App\Services\Secciones\ArchivoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithNube;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Concerns\ProveedorNubeFalso;
use Tests\TestCase;

/**
 * "Vincular desde la nube": inspeccionar, vincular, sincronizar, pausar y
 * desvincular orígenes de una sección, quién puede hacerlo, el navegador de
 * carpetas y la protección de los documentos espejo.
 */
class OrigenesSeccionTest extends TestCase
{
    use InteractsWithNube, InteractsWithSecciones, RefreshDatabase;

    private const ENLACE_ONEDRIVE = 'https://1drv.ms/f/s!AbCdEfGh123';

    private const ENLACE_DRIVE_ARCHIVO = 'https://drive.google.com/file/d/1AbCdEfGhIjKlMnOpQ/view?usp=sharing';

    private ProveedorNubeFalso $nube;

    private Seccion $seccion;

    private NubeCuenta $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->nube = new ProveedorNubeFalso;
        $this->app->instance(MicrosoftGraph::class, $this->nube);
        $this->app->instance(EnlacePublico::class, $this->nube);
        $this->seccion = Seccion::factory()->create();
        $this->cuenta = $this->cuentaDeNube(ProveedorNube::Microsoft);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge(['cuenta_id' => $this->cuenta->id, 'enlace' => self::ENLACE_ONEDRIVE], $extra);
    }

    private function origenEn(?Seccion $seccion = null, array $atributos = []): SeccionOrigen
    {
        return SeccionOrigen::forceCreate(array_merge([
            'seccion_id' => ($seccion ?? $this->seccion)->id,
            'nube_cuenta_id' => $this->cuenta->id,
            'tipo' => 'carpeta',
            'proveedor' => 'microsoft',
            'remoto_item_id' => 'raiz-1',
            'enlace_original' => self::ENLACE_ONEDRIVE,
            'nombre' => 'Carpeta Mantenimiento',
            'incluir_subcarpetas' => true,
            'estado' => 'activo',
        ], $atributos));
    }

    private function espejo(SeccionOrigen $origen, string $ruta, ?int $estacionId = null): SeccionArchivo
    {
        $archivo = new SeccionArchivo([
            'nombre_original' => basename($ruta),
            'ruta' => 'secciones/'.$origen->seccion_id.'/'.md5($ruta).'.pdf',
            'mime' => 'application/pdf',
            'extension' => 'pdf',
            'tamano' => 100,
        ]);
        $archivo->forceFill([
            'seccion_id' => $origen->seccion_id,
            'seccion_origen_id' => $origen->id,
            'remoto_id' => md5($ruta),
            'remoto_ruta' => $ruta,
            'remoto_version' => 'v1',
            'estacion_id' => $estacionId,
        ])->save();

        return $archivo;
    }

    private function estacion(): User
    {
        $this->seed(RoleSeeder::class);
        $usuario = User::factory()->create(['estacion_id' => Estacion::factory()->create()->id]);
        $usuario->assignRole('Estación');

        return $usuario;
    }

    public function test_admin_inspects_a_folder_and_gets_totals_and_skipped_reasons(): void
    {
        $this->nube->inspeccion = new OrigenRemoto(OrigenRemoto::CARPETA, 'd1', 'i1', 'Mantenimiento', 12, ['zip' => 2, 'extension_no_permitida' => 1], 4);
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.inspeccionar', $this->seccion), $this->datos())
            ->assertOk()
            ->assertJson(['success' => true, 'error' => null, 'data' => [
                'tipo' => 'carpeta', 'nombre' => 'Mantenimiento', 'archivos' => 12, 'excel' => 4, 'omitidos_total' => 3,
            ]])
            ->assertJsonCount(2, 'data.omitidos');

        $this->assertSame(0, SeccionOrigen::query()->count(), 'Inspeccionar no guarda nada.');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function enlacesPeligrosos(): array
    {
        return [
            'otro sitio' => ['https://evil.example.com/f/s!abc'],
            'ip interna' => ['https://169.254.169.254/latest/meta-data'],
            'ip privada' => ['https://10.0.0.5/f/s!abc'],
            'sin https' => ['http://1drv.ms/f/s!abc'],
            'con credenciales' => ['https://usuario:clave@1drv.ms/f/s!abc'],
            'de otra nube' => ['https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOpQ'],
            'puerto raro' => ['https://1drv.ms:8443/f/s!abc'],
            'otro esquema' => ['file:///etc/passwd'],
        ];
    }

    #[DataProvider('enlacesPeligrosos')]
    public function test_links_to_disallowed_hosts_or_internal_addresses_are_rejected_before_any_request(string $enlace): void
    {
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.inspeccionar', $this->seccion), $this->datos(['enlace' => $enlace]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('enlace');

        $this->postJson(route('secciones.origenes.store', $this->seccion), $this->datos(['enlace' => $enlace]))->assertStatus(422);

        $this->assertSame(0, $this->nube->inspecciones);
        $this->assertSame(0, SeccionOrigen::query()->count());
    }

    #[DataProvider('enlacesPeligrosos')]
    public function test_public_links_reject_the_same_dangerous_addresses(string $enlace): void
    {
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.inspeccionar', $this->seccion), ['cuenta_id' => null, 'enlace' => $enlace])->assertStatus(422);
        $this->assertSame(0, $this->nube->inspecciones);
    }

    public function test_only_active_accounts_can_be_chosen(): void
    {
        $inactiva = $this->cuentaDeNube(ProveedorNube::Google, ['estado' => EstadoCuentaNube::RequiereReconexion]);
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.inspeccionar', $this->seccion), $this->datos(['cuenta_id' => $inactiva->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cuenta_id');
    }

    public function test_admin_links_a_folder_it_is_logged_and_the_job_is_queued(): void
    {
        Queue::fake();
        $usuario = $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.store', $this->seccion), $this->datos())
            ->assertCreated()
            ->assertJsonPath('data.tipo', 'carpeta')
            ->assertJsonPath('data.estado', 'activo')
            ->assertJsonMissingPath('data.enlace_original');

        $origen = SeccionOrigen::query()->firstOrFail();
        $this->assertSame($this->cuenta->id, $origen->nube_cuenta_id);
        $this->assertTrue($origen->alimenta_tablas, 'Por defecto sigue a la sección: con tabla, alimenta tablas.');
        $this->assertSame('raiz-1', $origen->remoto_item_id);
        Queue::assertPushed(SincronizarOrigenJob::class, fn (SincronizarOrigenJob $job) => $job->origenId === $origen->id && $job->queue === 'nube');

        $registro = Activity::query()->where('event', 'origen_vinculado')->firstOrFail();
        $this->assertSame($usuario->id, $registro->causer_id);
        $this->assertStringNotContainsString('1drv.ms', (string) json_encode($registro->properties));
    }

    public function test_documents_only_sections_default_to_not_feeding_tables(): void
    {
        Queue::fake();
        $solo = Seccion::factory()->create(['solo_documentos' => true]);
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.store', $solo), $this->datos())->assertCreated();

        $this->assertFalse(SeccionOrigen::query()->firstOrFail()->alimenta_tablas);
    }

    public function test_a_public_link_to_a_single_file_can_be_linked_without_an_account(): void
    {
        Queue::fake();
        $this->nube->inspeccion = new OrigenRemoto(OrigenRemoto::ARCHIVO, null, EnlacePublico::ID_ARCHIVO, 'Reporte.xlsx');
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.store', $this->seccion), ['cuenta_id' => null, 'enlace' => self::ENLACE_DRIVE_ARCHIVO])
            ->assertCreated();

        $origen = SeccionOrigen::query()->firstOrFail();
        $this->assertNull($origen->nube_cuenta_id);
        $this->assertSame('google', $origen->proveedor);
        $this->assertSame('archivo', $origen->tipo);
        $this->assertSame(EnlacePublico::ID_ARCHIVO, $origen->remoto_item_id);
    }

    public function test_the_same_folder_cannot_be_linked_twice_to_a_section(): void
    {
        Queue::fake();
        $this->nube->inspeccion = new OrigenRemoto(OrigenRemoto::CARPETA, 'd1', 'i1', 'Mantenimiento', 1);
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.store', $this->seccion), $this->datos())->assertCreated();
        $this->postJson(route('secciones.origenes.store', $this->seccion), $this->datos())->assertStatus(422)->assertJsonValidationErrors('enlace');
    }

    public function test_sync_now_queues_the_job_and_answers_202(): void
    {
        Queue::fake();
        $origen = $this->origenEn();
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.sincronizar', [$this->seccion, $origen]))
            ->assertStatus(202)
            ->assertJsonPath('data.en_cola', true);

        Queue::assertPushed(SincronizarOrigenJob::class, fn (SincronizarOrigenJob $job) => $job->origenId === $origen->id);
    }

    public function test_origin_of_another_section_is_not_reachable_through_this_one(): void
    {
        $ajeno = $this->origenEn(Seccion::factory()->create());
        $this->comoAdministrador();

        $this->postJson(route('secciones.origenes.sincronizar', [$this->seccion, $ajeno]))->assertNotFound();
        $this->deleteJson(route('secciones.origenes.destroy', [$this->seccion, $ajeno]))->assertNotFound();
    }

    public function test_admin_pauses_and_resumes_an_origin(): void
    {
        $origen = $this->origenEn();
        $this->comoAdministrador();

        $this->putJson(route('secciones.origenes.update', [$this->seccion, $origen]), ['estado' => 'pausado'])
            ->assertOk()->assertJsonPath('data.estado', 'pausado');
        $this->assertSame(EstadoOrigen::Pausado, $origen->fresh()->estado);

        $this->putJson(route('secciones.origenes.update', [$this->seccion, $origen]), ['estado' => 'activo', 'incluir_subcarpetas' => false])
            ->assertOk();
        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertFalse($origen->fresh()->incluir_subcarpetas);
        $this->assertDatabaseHas('activity_log', ['event' => 'origen_actualizado', 'subject_id' => $origen->id]);
    }

    public function test_an_origin_cannot_be_resumed_while_its_account_needs_reconnection(): void
    {
        $this->cuenta->forceFill(['estado' => EstadoCuentaNube::RequiereReconexion])->save();
        $origen = $this->origenEn(atributos: ['estado' => 'requiere_reconexion']);
        $this->comoAdministrador();

        $this->putJson(route('secciones.origenes.update', [$this->seccion, $origen]), ['estado' => 'activo'])
            ->assertStatus(422)->assertJsonValidationErrors('estado');
    }

    public function test_unlinking_can_keep_the_documents_as_normal_ones(): void
    {
        $origen = $this->origenEn();
        $documento = $this->espejo($origen, 'Sub/Informe.pdf');
        $usuario = $this->comoAdministrador();

        $this->deleteJson(route('secciones.origenes.destroy', [$this->seccion, $origen]), ['conservar' => true])->assertOk();

        $this->assertSoftDeleted('seccion_origenes', ['id' => $origen->id]);
        $this->assertSame('', SeccionOrigen::withTrashed()->find($origen->id)->enlace_original);
        $documento->refresh();
        $this->assertNull($documento->seccion_origen_id);
        $this->assertNull($documento->remoto_id);
        $this->assertFalse($documento->esEspejo());
        $this->assertNull($documento->deleted_at);
        $this->assertDatabaseHas('activity_log', ['event' => 'origen_desvinculado', 'causer_id' => $usuario->id]);

        // Ya es un documento normal: se puede borrar a mano.
        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $documento]))->assertRedirect();
    }

    public function test_unlinking_can_archive_the_documents_instead(): void
    {
        $origen = $this->origenEn();
        $documento = $this->espejo($origen, 'Sub/Informe.pdf');
        $this->comoAdministrador();

        $this->deleteJson(route('secciones.origenes.destroy', [$this->seccion, $origen]), ['conservar' => false])->assertOk();

        $this->assertSoftDeleted('seccion_archivos', ['id' => $documento->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'documento_archivado_nube', 'subject_id' => $documento->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'origen_desvinculado']);
    }

    public function test_form_submissions_redirect_back_with_a_status_message(): void
    {
        Queue::fake();
        $origen = $this->origenEn();
        $this->comoAdministrador();

        $this->from(route('secciones.show', $this->seccion))
            ->post(route('secciones.origenes.sincronizar', [$this->seccion, $origen]))
            ->assertRedirect(route('secciones.show', $this->seccion))
            ->assertSessionHas('status');
        $this->from(route('secciones.show', $this->seccion))
            ->delete(route('secciones.origenes.destroy', [$this->seccion, $origen]), ['conservar' => '1'])
            ->assertRedirect(route('secciones.show', $this->seccion))
            ->assertSessionHas('status');
    }

    public function test_station_accounts_and_roleless_users_cannot_manage_origins(): void
    {
        $origen = $this->origenEn();
        $this->seccion->estacionesAprobadas()->sync([]);

        foreach ([$this->estacion(), $this->usuarioConRol(null)] as $usuario) {
            $this->actingAsTwoFactorVerified($usuario);

            $this->postJson(route('secciones.origenes.inspeccionar', $this->seccion), $this->datos())->assertForbidden();
            $this->postJson(route('secciones.origenes.store', $this->seccion), $this->datos())->assertForbidden();
            $this->postJson(route('secciones.origenes.sincronizar', [$this->seccion, $origen]))->assertForbidden();
            $this->putJson(route('secciones.origenes.update', [$this->seccion, $origen]), ['estado' => 'pausado'])->assertForbidden();
            $this->deleteJson(route('secciones.origenes.destroy', [$this->seccion, $origen]))->assertForbidden();
        }

        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $origen = $this->origenEn();

        $this->post(route('secciones.origenes.store', $this->seccion), $this->datos())->assertRedirect(route('login'));
        $this->post(route('secciones.origenes.sincronizar', [$this->seccion, $origen]))->assertRedirect(route('login'));
        $this->put(route('secciones.origenes.update', [$this->seccion, $origen]))->assertRedirect(route('login'));
        $this->delete(route('secciones.origenes.destroy', [$this->seccion, $origen]))->assertRedirect(route('login'));
    }

    public function test_mirror_documents_cannot_be_deleted_or_relinked_by_hand(): void
    {
        $origen = $this->origenEn();
        $espejo = $this->espejo($origen, 'Informe.pdf');
        $this->comoAdministrador();

        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $espejo]))->assertForbidden();
        $this->post(route('secciones.archivos.nube.vincular', [$this->seccion, $espejo]), ['enlace' => self::ENLACE_DRIVE_ARCHIVO])->assertForbidden();
        $this->post(route('secciones.archivos.nube.sincronizar', [$this->seccion, $espejo]))->assertForbidden();
        $this->delete(route('secciones.archivos.nube.desvincular', [$this->seccion, $espejo]))->assertForbidden();

        $this->assertNotSoftDeleted('seccion_archivos', ['id' => $espejo->id]);
        $this->assertSame($origen->id, $espejo->fresh()->seccion_origen_id);
    }

    public function test_zone_sees_the_origins_panel_and_mirror_rows_without_delete_button(): void
    {
        $origen = $this->origenEn(atributos: ['sincronizado_en' => now()->subMinutes(3), 'resumen' => ['archivos' => 1, 'omitidos' => ['zip' => 2]]]);
        $this->espejo($origen, 'Sub/Informe.pdf');
        $this->comoAdministrador();

        $respuesta = $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos', 'carpeta' => 'Sub']))->assertOk();

        $respuesta->assertSee('Orígenes de la sección')
            ->assertSee('Vincular desde la nube')
            ->assertSee('Carpeta Mantenimiento')
            ->assertSee('Sincronizar ahora')
            ->assertSee('Sincronizado de la nube')
            ->assertSee('Informe.pdf')
            ->assertDontSee('aria-label="Eliminar"', false)
            ->assertDontSee('Vincular a la nube');
    }

    public function test_modal_explains_that_the_admin_must_configure_connections_when_none_exist(): void
    {
        $this->cuenta->delete();
        $this->comoAdministrador();

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertSee('Aún no hay ninguna cuenta de nube conectada')
            ->assertSee(route('nube.cuentas.index'), false);
    }

    public function test_warning_links_to_connections_when_an_account_needs_reconnection(): void
    {
        $this->cuenta->forceFill(['estado' => EstadoCuentaNube::RequiereReconexion])->save();
        $this->comoAdministrador();

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertSee('necesita reconectarse')
            ->assertSee('persona@ejemplo.test');
    }

    public function test_station_accounts_never_see_the_origins_panel_and_see_synced_documents_only_with_section_access(): void
    {
        $estacion = $this->estacion();
        $origen = $this->origenEn();
        $archivo = $this->espejo($origen, 'Documento-sincronizado.pdf');
        $this->actingAsTwoFactorVerified($estacion);

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos']))->assertNotFound();
        $this->get(route('secciones.archivos.show', [$this->seccion, $archivo]))->assertNotFound();

        // Con permiso Ver sobre la sección completa ve todos sus documentos, también los de la nube.
        $this->seccion->estacionesAprobadas()->sync([$estacion->estacion_id => ['nivel' => 'ver']]);

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos']))
            ->assertOk()
            ->assertDontSee('Orígenes de la sección')
            ->assertDontSee('Vincular desde la nube')
            ->assertDontSee('Carpeta Mantenimiento')
            ->assertSee('Documento-sincronizado.pdf');
        $this->delete(route('secciones.archivos.destroy', [$this->seccion, $archivo]))->assertForbidden();
    }

    public function test_folder_navigator_shows_breadcrumbs_subfolders_with_counts_and_only_the_current_folder(): void
    {
        $origen = $this->origenEn();
        $this->espejo($origen, 'Raiz.pdf');
        $this->espejo($origen, 'Obras/Uno.pdf');
        $this->espejo($origen, 'Obras/Fase2/Dos.pdf');
        $this->espejo($origen, 'Obras/Fase2/Tres.pdf');
        $this->espejo($origen, 'Otra/Cuatro.pdf');
        $this->comoAdministrador();

        $raiz = $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos']))->assertOk();
        $raiz->assertSee('Raiz.pdf')->assertDontSee('Uno.pdf')->assertDontSee('Dos.pdf')
            ->assertSee('Obras')->assertSee('3 documentos')->assertSee('Otra')->assertSee('1 documento');

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos', 'carpeta' => 'Obras']))
            ->assertOk()
            ->assertSee('Uno.pdf')->assertDontSee('Raiz.pdf')->assertDontSee('Dos.pdf')->assertDontSee('Cuatro.pdf')
            ->assertSee('Fase2')->assertSee('aria-current="page"', false);

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos', 'carpeta' => 'Obras/Fase2']))
            ->assertOk()->assertSee('Dos.pdf')->assertSee('Tres.pdf')->assertDontSee('Uno.pdf');
    }

    public function test_full_text_search_covers_every_folder(): void
    {
        $origen = $this->origenEn();
        $this->espejo($origen, 'Obras/Fase2/Presupuesto-final.pdf');
        $this->espejo($origen, 'Otra/Presupuesto-inicial.pdf');
        $this->comoAdministrador();

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos', 'q' => 'Presupuesto', 'carpeta' => 'Otra']))
            ->assertOk()
            ->assertSee('Presupuesto-final.pdf')
            ->assertSee('Presupuesto-inicial.pdf');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function carpetasInvalidas(): array
    {
        return [
            'sube de nivel' => ['../secreto'],
            'sube en medio' => ['Obras/../../x'],
            'barra inicial' => ['/etc'],
            'barra final' => ['Obras/'],
            'doble barra' => ['Obras//Fase2'],
            'punto' => ['./Obras'],
            'barra invertida' => ['Obras\\Fase2'],
            'muy larga' => [str_repeat('a', 501)],
        ];
    }

    #[DataProvider('carpetasInvalidas')]
    public function test_folder_parameter_is_validated(string $carpeta): void
    {
        $this->comoAdministrador();

        $this->get(route('secciones.show', ['seccion' => $this->seccion, 'tab' => 'documentos', 'carpeta' => $carpeta]))
            ->assertSessionHasErrors('carpeta');
    }

    public function test_legacy_per_file_routes_keep_working_for_normal_documents(): void
    {
        $this->comoAdministrador();
        $documento = app(ArchivoService::class)->guardar($this->seccion, $this->excel([['a'], ['b']]), auth()->user());

        $this->post(route('secciones.archivos.nube.vincular', [$this->seccion, $documento]), ['enlace' => self::ENLACE_DRIVE_ARCHIVO])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertNotNull($documento->fresh()->seccion_origen_id);
        $this->assertFalse($documento->fresh()->esEspejo());
    }
}
