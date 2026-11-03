<?php

namespace Tests\Feature\Nube;

use App\Enums\EstadoImportacion;
use App\Enums\EstadoOrigen;
use App\Enums\TipoColumna;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Nube\Proveedores\EnlacePublico;
use App\Services\Nube\Proveedores\MicrosoftGraph;
use App\Services\Secciones\LibroImportacionService;
use App\Services\Secciones\SincronizadorOrigen;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Concerns\ProveedorNubeFalso;
use Tests\TestCase;

/**
 * Tablas espejo: los Excel de un origen de nube mantienen tablas de solo
 * lectura siempre iguales a la nube (mapeo confirmado una sola vez).
 */
class TablasEspejoTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private ProveedorNubeFalso $nube;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->nube = new ProveedorNubeFalso;
        $this->app->instance(EnlacePublico::class, $this->nube);
        $this->app->instance(MicrosoftGraph::class, $this->nube);
    }

    /**
     * Excel real con una hoja por entrada (nombre => filas).
     *
     * @param  array<string, list<list<mixed>>>  $hojas
     */
    private function libro(array $hojas): string
    {
        $libro = new Spreadsheet;
        $libro->removeSheetByIndex(0);

        foreach ($hojas as $nombre => $filas) {
            $hoja = $libro->createSheet();
            $hoja->setTitle($nombre);
            $hoja->fromArray($filas, null, 'A1', true);
        }

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($libro))->save($ruta);

        return (string) file_get_contents($ruta);
    }

    /**
     * @param  list<list<mixed>>  $filas
     */
    private function hoja(array $filas, string $nombre = 'Equipos'): string
    {
        return $this->libro([$nombre => $filas]);
    }

    private function origen(string $tipo = SeccionOrigen::TIPO_CARPETA, bool $alimenta = true, ?Seccion $seccion = null): SeccionOrigen
    {
        return SeccionOrigen::forceCreate([
            'seccion_id' => ($seccion ?? Seccion::factory()->create())->id,
            'tipo' => $tipo,
            'proveedor' => 'microsoft',
            'remoto_item_id' => 'raiz-1',
            'enlace_original' => 'https://1drv.ms/f/s!fake',
            'nombre' => 'Carpeta de prueba',
            'incluir_subcarpetas' => true,
            'alimenta_tablas' => $alimenta,
            'estado' => EstadoOrigen::Activo,
            'creado_por' => User::factory()->create()->id,
        ]);
    }

    private function sincronizar(SeccionOrigen $origen): string
    {
        return app(SincronizadorOrigen::class)->sincronizar($origen->fresh());
    }

    /**
     * Confirma el primer mapeo como lo haría el Administrador desde la pantalla.
     */
    private function confirmar(SeccionOrigen $origen, string $modo = 'actualizar', ?int $llave = 0): void
    {
        $previa = SeccionImportacion::query()->where('origen', 'nube')->where('estado', EstadoImportacion::VistaPrevia->value)->firstOrFail();
        $hojas = [];

        foreach (app(LibroImportacionService::class)->propuestas($previa) as $indice => $propuesta) {
            $hojas[$indice] = [
                'incluir' => 1,
                'fila_encabezado' => $propuesta['fila_encabezado'],
                'mapeo' => $propuesta['mapeo'],
                'tipos' => $propuesta['tipos'],
                'llave' => $llave,
            ];
        }

        $this->post(route('secciones.importaciones.libro.aplicar', [$origen->seccion, $previa]), ['modo' => $modo, 'hojas' => $hojas])->assertRedirect();
    }

    /**
     * Origen ya mapeado con un Excel de equipos (llave: folio).
     *
     * @return array{0: SeccionOrigen, 1: SeccionTabla}
     */
    private function origenMapeado(string $tipo = SeccionOrigen::TIPO_CARPETA): array
    {
        $this->comoAdministrador();
        $origen = $this->origen($tipo);
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos'], ['C3', 'tres']]), 'v1');
        $this->sincronizar($origen);
        $this->confirmar($origen);

        return [$origen->fresh(), $origen->tablas()->firstOrFail()];
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private function filasDe(SeccionTabla $tabla): array
    {
        return SeccionFila::query()->where('seccion_tabla_id', $tabla->id)->orderBy('id')->get()->pluck('datos', 'datos.folio')->all();
    }

    public function test_first_excel_leaves_the_origin_pending_mapping_with_a_preview_while_documents_still_sync(): void
    {
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');
        $this->nube->poner('p1', 'Manual.pdf', '%PDF-1.4 prueba', 'v1');

        $this->sincronizar($origen);

        $this->assertSame(EstadoOrigen::PendienteMapeo, $origen->fresh()->estado);
        $this->assertSame(2, SeccionArchivo::query()->count(), 'Los documentos igual se sincronizan.');
        $previa = SeccionImportacion::query()->where('origen', 'nube')->sole();
        $this->assertSame(EstadoImportacion::VistaPrevia, $previa->estado);
        $this->assertSame(0, $origen->seccion->tablas()->count(), 'Las tablas esperan la confirmación.');
        $this->assertSame(0, SeccionMapeo::query()->count());
    }

    public function test_the_preview_warns_that_the_mapping_is_used_for_every_sync(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');
        $this->sincronizar($origen);
        $previa = SeccionImportacion::query()->where('origen', 'nube')->sole();

        $this->get(route('secciones.importaciones.show', [$origen->seccion, $previa]))
            ->assertOk()
            ->assertSee('Este mapeo se usará para todas las sincronizaciones')
            ->assertSee('Equipos · Equipos');
    }

    public function test_confirming_the_mapping_creates_mirror_tables_saves_the_mapping_and_activates_the_origin(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos']]), 'v1');
        $this->sincronizar($origen);

        $this->confirmar($origen);

        $tabla = $origen->tablas()->sole();
        $this->assertTrue($tabla->esEspejo());
        $this->assertSame('Equipos · Equipos', $tabla->nombre, 'Origen carpeta: «Archivo» · «Hoja».');
        $this->assertSame(2, $tabla->filas()->count());
        $mapeo = SeccionMapeo::query()->sole();
        $this->assertSame('Equipos', $mapeo->hoja);
        $this->assertSame('folio', $mapeo->llave_clave);
        $this->assertSame($tabla->id, $mapeo->seccion_tabla_id);
        $this->assertContains('folio', array_column($mapeo->columnas, 'clave'));
        $this->assertNotNull($mapeo->confirmado_en);
        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertSame(1, Activity::query()->where('event', 'mapeo_confirmado')->count());
    }

    public function test_a_file_origin_names_the_table_after_the_sheet(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen(SeccionOrigen::TIPO_ARCHIVO);
        $this->nube->poner('x1', 'Archivo.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']], 'Extintores'), 'v1');
        $this->sincronizar($origen);

        $this->confirmar($origen);

        $this->assertSame('Extintores', $origen->tablas()->sole()->nombre);
    }

    public function test_a_modified_excel_updates_adds_and_archives_rows_and_keeps_the_table_identical_to_it(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno cambiado'], ['B2', null], ['D4', 'cuatro']]), 'v2');
        $this->sincronizar($origen);

        $vigentes = $tabla->filas()->get()->pluck('datos', 'datos.folio');
        $this->assertSame(['A1', 'B2', 'D4'], $vigentes->keys()->sort()->values()->all());
        $this->assertSame('uno cambiado', $vigentes['A1']['equipo']);
        $this->assertNull($vigentes['B2']['equipo']);
        $this->assertSame(1, SeccionFila::onlyTrashed()->where('seccion_tabla_id', $tabla->id)->count(), 'C3 se archiva (baja lógica).');
        $aplicada = SeccionImportacion::query()->where('origen', 'nube')->where('estado', 'aplicada')->orderByDesc('id')->first();
        $this->assertSame(1, $aplicada->resumen['nuevas']);
        $this->assertSame(2, Activity::query()->where('event', 'tabla_espejo_actualizada')->count(), 'La confirmación y la actualización.');
    }

    public function test_mirror_applications_are_audited_and_cannot_be_undone(): void
    {
        [$origen] = $this->origenMapeado();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos'], ['C3', 'tres'], ['D4', 'cuatro']]), 'v2');
        $this->sincronizar($origen);

        $aplicada = SeccionImportacion::query()->where('origen', 'nube')->where('estado', 'aplicada')->orderByDesc('id')->firstOrFail();

        $this->assertNotNull(Activity::query()->where('event', 'tabla_espejo_actualizada')->latest('id')->first());
        $this->post(route('secciones.importaciones.deshacer', [$origen->seccion, $aplicada]))->assertSessionHasErrors('importacion');
    }

    public function test_without_a_key_the_table_is_replaced_only_when_the_sheet_content_changed(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos']]), 'v1');
        $this->sincronizar($origen);
        $this->confirmar($origen, 'agregar', null);
        $tabla = $origen->tablas()->sole();
        $this->assertNull(SeccionMapeo::query()->sole()->llave_clave);
        $antes = SeccionImportacion::query()->where('origen', 'nube')->count();

        // Cambia solo la versión (mismo contenido de la hoja): no se reaplica.
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos']]), 'v2');
        $this->sincronizar($origen);
        $this->assertSame($antes, SeccionImportacion::query()->where('origen', 'nube')->count());

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos'], ['Z9', 'nuevo']]), 'v3');
        $this->sincronizar($origen);

        $this->assertSame(3, $tabla->filas()->count());
        $this->assertSame(2, SeccionFila::onlyTrashed()->where('seccion_tabla_id', $tabla->id)->count(), 'Reemplaza todo: las anteriores se archivan.');
    }

    public function test_a_new_excel_column_is_created_with_an_inferred_type_and_added_to_the_mapping(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo', 'Cantidad'], ['A1', 'uno', 5], ['B2', 'dos', 7], ['C3', 'tres', 9]]), 'v2');
        $this->sincronizar($origen);

        $columna = $tabla->columnas()->where('nombre', 'Cantidad')->sole();
        $this->assertSame(TipoColumna::Numero, $columna->tipo);
        $this->assertContains($columna->clave, array_column(SeccionMapeo::query()->sole()->columnas, 'clave'));
        $this->assertEquals(5, $tabla->filas()->get()->firstWhere('datos.folio', 'A1')->datos[$columna->clave]);
    }

    public function test_a_column_removed_from_the_excel_is_kept_in_the_table(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio'], ['A1'], ['B2'], ['C3'], ['D4']]), 'v2');
        $this->sincronizar($origen);

        $this->assertNotNull($tabla->columnas()->where('clave', 'equipo')->first());
        $this->assertSame(4, $tabla->filas()->count());
        $this->assertNull($tabla->filas()->get()->firstWhere('datos.folio', 'D4')->datos['equipo'] ?? null);
    }

    public function test_a_new_sheet_creates_its_table_automatically_with_a_review_notice(): void
    {
        [$origen] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->libro([
            'Equipos' => [['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos'], ['C3', 'tres']],
            'Extintores' => [['Folio', 'Marca'], ['E1', 'Kidde'], ['E2', 'Amerex']],
        ]), 'v2');
        $this->sincronizar($origen);

        $nueva = $origen->tablas()->where('nombre', 'Equipos · Extintores')->sole();
        $this->assertTrue($nueva->esEspejo());
        $this->assertSame(2, $nueva->filas()->count());
        $mapeo = SeccionMapeo::query()->where('hoja', 'Extintores')->sole();
        $this->assertSame('folio', $mapeo->llave_clave, 'Se sugiere la llave.');
        $this->assertNull($mapeo->confirmado_en, 'Sin revisar todavía.');
        $this->assertStringContainsString('Tabla nueva creada automáticamente: revisar mapeo', (string) $origen->fresh()->sincronizacion_error);
    }

    public function test_a_new_excel_in_an_already_mapped_folder_creates_its_table_without_asking_again(): void
    {
        [$origen] = $this->origenMapeado();

        $this->nube->poner('x2', 'Telefonos.xlsx', $this->hoja([['Clave', 'Nombre'], ['T1', 'Ana'], ['T2', 'Luis']], 'Hoja1'), 'v1');
        $this->sincronizar($origen);

        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertSame(2, $origen->tablas()->count());
        $this->assertNotNull($origen->tablas()->where('nombre', 'Telefonos · Hoja1')->first());
        $this->assertSame(0, SeccionImportacion::query()->where('estado', 'vista_previa')->count());
    }

    public function test_an_empty_sheet_is_held_and_the_table_is_untouched_until_the_administrator_decides(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->libro(['Equipos' => [['Folio', 'Equipo'], [null, null]], 'Otra' => [['a', 'b'], ['1', '2']]]), 'v2');
        $this->sincronizar($origen);

        $this->assertSame(3, $tabla->filas()->count());
        $mapeo = SeccionMapeo::query()->where('hoja', 'Equipos')->sole();
        $this->assertSame('hoja_vacia', $mapeo->retenido['motivo']);
        $this->assertSame(1, Activity::query()->where('event', 'cambio_masivo_retenido')->count());

        $this->post(route('secciones.origenes.mapeo.aplicar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id])->assertRedirect();

        $this->assertSame(0, $tabla->filas()->count());
        $this->assertNull($mapeo->fresh()->retenido);
        $this->assertSame(1, Activity::query()->where('event', 'cambio_masivo_aplicado')->count());
    }

    public function test_a_mass_archive_is_held_and_ignoring_it_keeps_the_table_and_does_not_hold_it_again(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v2');
        $this->sincronizar($origen);

        $mapeo = SeccionMapeo::query()->sole();
        $this->assertSame('cambio_masivo', $mapeo->retenido['motivo']);
        $this->assertSame(2, $mapeo->retenido['archivar']);
        $this->assertSame(3, $tabla->filas()->count(), 'No se aplicó.');

        $this->post(route('secciones.origenes.mapeo.ignorar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id])->assertRedirect();

        $this->assertNull($mapeo->fresh()->retenido);
        $this->assertSame(3, $tabla->filas()->count());
        $this->assertSame(1, Activity::query()->where('event', 'cambio_masivo_ignorado')->count());

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v3');
        $this->sincronizar($origen);
        $this->assertNull($mapeo->fresh()->retenido, 'El mismo contenido ya ignorado no vuelve a retenerse.');
    }

    public function test_applying_a_held_mass_change_archives_the_rows(): void
    {
        [$origen, $tabla] = $this->origenMapeado();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v2');
        $this->sincronizar($origen);
        $mapeo = SeccionMapeo::query()->sole();

        $this->post(route('secciones.origenes.mapeo.aplicar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id])->assertRedirect();

        $this->assertSame(1, $tabla->filas()->count());
        $this->assertNull($mapeo->fresh()->retenido);
    }

    public function test_the_archive_threshold_is_configurable(): void
    {
        config(['secciones.nube.espejo_max_archivado_pct' => 90]);
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v2');
        $this->sincronizar($origen);

        $this->assertSame(1, $tabla->filas()->count(), '2 de 3 (67 %) no pasa el 90 %.');
        $this->assertNull(SeccionMapeo::query()->sole()->retenido);
    }

    public function test_a_corrupt_excel_does_not_touch_the_table(): void
    {
        [$origen, $tabla] = $this->origenMapeado();

        $this->nube->poner('x1', 'Equipos.xlsx', 'esto no es un xlsx, quedó a medio subir', 'v2');
        $this->sincronizar($origen);

        $this->assertSame(3, $tabla->filas()->count());
        $this->assertSame(0, SeccionFila::onlyTrashed()->count());
    }

    public function test_held_and_ignore_routes_require_the_mapping_to_belong_to_the_origin(): void
    {
        [$origen] = $this->origenMapeado();
        $otro = $this->origen();

        $this->post(route('secciones.origenes.mapeo.ignorar-retenido', [$origen->seccion, $origen]), ['mapeo' => 99999])->assertSessionHasErrors('mapeo');
        $this->post(route('secciones.origenes.mapeo.ignorar-retenido', [$otro->seccion, $otro]), ['mapeo' => SeccionMapeo::query()->value('id')])->assertSessionHasErrors('mapeo');
    }

    public function test_a_mixed_section_sends_excel_to_tables_and_pdf_to_documents(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');
        $this->nube->poner('p1', 'Manual.pdf', '%PDF-1.4 prueba', 'v1');
        $this->sincronizar($origen);

        $this->confirmar($origen);

        $this->assertSame(2, $origen->archivos()->count());
        $this->assertSame(1, $origen->tablas()->count());
        $this->assertSame(1, SeccionMapeo::query()->count(), 'Solo el Excel tiene mapeo; el PDF es solo documento.');
    }

    public function test_an_origin_that_does_not_feed_tables_never_asks_for_a_mapping(): void
    {
        $origen = $this->origen(alimenta: false);
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');

        $this->sincronizar($origen);

        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertSame(0, SeccionImportacion::query()->count());
    }

    public function test_editing_the_mapping_changes_the_key_and_reapplies_the_sheet(): void
    {
        [$origen, $tabla] = $this->origenMapeado();
        $mapeo = SeccionMapeo::query()->sole();

        $this->get(route('secciones.origenes.mapeo.edit', [$origen->seccion, $origen]))->assertOk()->assertSee('Equipos')->assertSee('Fila de encabezados');
        $this->post(route('secciones.origenes.mapeo.update', [$origen->seccion, $origen]), [
            'mapeos' => [$mapeo->id => ['fila_encabezado' => 0, 'llave_clave' => 'equipo']],
        ])->assertRedirect();

        $this->assertSame('equipo', $mapeo->fresh()->llave_clave);
        $this->assertSame($this->auth(), $mapeo->fresh()->confirmado_por);
        $this->assertSame(1, Activity::query()->where('event', 'mapeo_confirmado')->where('description', 'Mapeo de columnas editado.')->count());
        $this->assertSame(3, $tabla->filas()->count());
    }

    public function test_editing_the_mapping_rejects_a_key_column_that_does_not_exist(): void
    {
        [$origen] = $this->origenMapeado();
        $mapeo = SeccionMapeo::query()->sole();

        $this->post(route('secciones.origenes.mapeo.update', [$origen->seccion, $origen]), [
            'mapeos' => [$mapeo->id => ['fila_encabezado' => 0, 'llave_clave' => 'no_existe']],
        ])->assertSessionHasErrors();

        $this->assertSame('folio', $mapeo->fresh()->llave_clave);
    }

    public function test_mirror_rows_have_no_station_unless_the_table_remembers_a_station_column(): void
    {
        [$origen, $tabla] = $this->origenMapeado();
        $this->assertSame(0, $tabla->filas()->whereNotNull('estacion_id')->count(), 'Solo zona: sin estación.');

        $estacion = Estacion::factory()->create(['nombre' => 'Cancún']);
        $tabla->forceFill(['columna_estacion_clave' => 'equipo'])->save();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno'], ['B2', 'dos'], ['C3', 'tres'], ['D4', 'Cancún']]), 'v2');
        $this->sincronizar($origen);

        $this->assertSame($estacion->id, $tabla->filas()->get()->firstWhere('datos.folio', 'D4')->estacion_id);
        $this->assertNull($tabla->filas()->get()->firstWhere('datos.folio', 'A1')->estacion_id);
    }

    public function test_the_single_sheet_apply_routes_are_closed_for_a_mapping_preview(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');
        $this->sincronizar($origen);
        $previa = SeccionImportacion::query()->where('origen', 'nube')->sole();

        $this->post(route('secciones.importaciones.aplicar', [$origen->seccion, $previa]), ['tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => [0 => 'nueva'], 'modo' => 'agregar'])->assertNotFound();
        $this->assertSame(0, $origen->seccion->tablas()->count());
    }

    public function test_discarding_the_preview_returns_the_origin_to_active_without_creating_tables(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v1');
        $this->sincronizar($origen);
        $previa = SeccionImportacion::query()->where('origen', 'nube')->sole();

        $this->delete(route('secciones.importaciones.destroy', [$origen->seccion, $previa]))->assertRedirect();

        $this->assertSame(EstadoOrigen::Activo, $origen->fresh()->estado);
        $this->assertSame(0, $origen->tablas()->count());
    }

    /**
     * @return array<string, array{0: string, 1: string}> [método HTTP, nombre de ruta]
     */
    public static function rutasDeMapeo(): array
    {
        return [
            'editar' => ['get', 'secciones.origenes.mapeo.edit'],
            'guardar' => ['post', 'secciones.origenes.mapeo.update'],
            'aplicar retenido' => ['post', 'secciones.origenes.mapeo.aplicar-retenido'],
            'ignorar retenido' => ['post', 'secciones.origenes.mapeo.ignorar-retenido'],
        ];
    }

    #[DataProvider('rutasDeMapeo')]
    public function test_stations_and_users_without_role_cannot_use_the_mapping_routes(string $metodo, string $ruta): void
    {
        [$origen] = $this->origenMapeado();
        $mapeo = SeccionMapeo::query()->sole();
        $datos = ['mapeo' => $mapeo->id, 'mapeos' => [$mapeo->id => ['fila_encabezado' => 0, 'llave_clave' => 'equipo']]];

        foreach ([Roles::ESTACION, null] as $rol) {
            $this->actingAsTwoFactorVerified($this->usuarioConRol($rol));
            $this->{$metodo}(route($ruta, [$origen->seccion, $origen]), $metodo === 'post' ? $datos : [])->assertForbidden();
        }

        $this->assertSame('folio', $mapeo->fresh()->llave_clave);
    }

    #[DataProvider('rutasDeMapeo')]
    public function test_the_mapping_routes_return_404_for_an_origin_of_another_section(string $metodo, string $ruta): void
    {
        [$origen] = $this->origenMapeado();
        $mapeo = SeccionMapeo::query()->sole();
        $otraSeccion = Seccion::factory()->create();
        $datos = ['mapeo' => $mapeo->id, 'mapeos' => [$mapeo->id => ['fila_encabezado' => 0, 'llave_clave' => 'equipo']]];

        $this->{$metodo}(route($ruta, [$otraSeccion, $origen]), $metodo === 'post' ? $datos : [])->assertNotFound();
    }

    public function test_only_a_held_mapping_can_be_applied_or_ignored(): void
    {
        [$origen, $tabla] = $this->origenMapeado();
        $mapeo = SeccionMapeo::query()->sole();
        $this->assertNull($mapeo->retenido);

        $this->post(route('secciones.origenes.mapeo.aplicar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id])->assertSessionHasErrors('mapeo');
        $this->post(route('secciones.origenes.mapeo.ignorar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id])->assertSessionHasErrors('mapeo');

        $this->assertSame(3, $tabla->filas()->count());
        $this->assertSame(0, Activity::query()->whereIn('event', ['cambio_masivo_aplicado', 'cambio_masivo_ignorado'])->count());
    }

    public function test_editing_the_mapping_that_would_archive_many_rows_needs_explicit_confirmation(): void
    {
        [$origen, $tabla] = $this->origenMapeado();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v2');
        $this->sincronizar($origen);
        $mapeo = SeccionMapeo::query()->sole();
        $motivoAntes = $mapeo->retenido['motivo'];
        $ruta = route('secciones.origenes.mapeo.update', [$origen->seccion, $origen]);
        $cambio = ['mapeos' => [$mapeo->id => ['fila_encabezado' => 0, 'llave_clave' => 'equipo']]];

        $this->post($ruta, $cambio)->assertSessionHasErrors('mapeo');

        $this->assertSame(3, $tabla->filas()->count(), 'Sin confirmar no se archiva nada.');
        $this->assertSame('folio', $mapeo->fresh()->llave_clave, 'El mapeo no cambia si el cambio no se aplicó.');
        $this->assertSame($motivoAntes, $mapeo->fresh()->retenido['motivo']);

        $this->post($ruta, $cambio + ['confirmar_masivo' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $tabla->filas()->count());
        $this->assertSame('equipo', $mapeo->fresh()->llave_clave);
        $registro = Activity::query()->where('event', 'mapeo_confirmado')->where('description', 'Mapeo de columnas editado.')->sole();
        $this->assertSame($this->auth(), $registro->causer_id);
        $this->assertTrue($registro->properties['confirmado_masivo']);
    }

    public function test_editing_several_sheets_is_all_or_nothing(): void
    {
        $this->comoAdministrador();
        $origen = $this->origen();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->libro([
            'Equipos' => [['Folio', 'Equipo'], ['A1', 'uno']],
            'Otros' => [['Folio', 'Otro'], ['B1', 'dos']],
        ]), 'v1');
        $this->sincronizar($origen);
        $this->confirmar($origen);
        $mapeos = SeccionMapeo::query()->orderBy('id')->get();
        $this->assertCount(2, $mapeos);

        $this->post(route('secciones.origenes.mapeo.update', [$origen->seccion, $origen]), ['mapeos' => [
            $mapeos[0]->id => ['fila_encabezado' => 0, 'llave_clave' => 'equipo'],
            $mapeos[1]->id => ['fila_encabezado' => 0, 'llave_clave' => 'no_existe'],
        ]])->assertSessionHasErrors();

        $this->assertSame('folio', $mapeos[0]->fresh()->llave_clave, 'La primera hoja no queda a medias.');
        $this->assertSame(0, Activity::query()->where('description', 'Mapeo de columnas editado.')->count());
    }

    public function test_applying_and_ignoring_held_changes_are_audited_with_their_author(): void
    {
        [$origen] = $this->origenMapeado();
        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'uno']]), 'v2');
        $this->sincronizar($origen);
        $mapeo = SeccionMapeo::query()->sole();
        $this->assertSame(1, Activity::query()->where('event', 'cambio_masivo_retenido')->count());

        $this->post(route('secciones.origenes.mapeo.ignorar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id]);
        $ignorado = Activity::query()->where('event', 'cambio_masivo_ignorado')->sole();
        $this->assertSame($this->auth(), $ignorado->causer_id);
        $this->assertSame($origen->seccion_id, $ignorado->subject_id);

        $this->nube->poner('x1', 'Equipos.xlsx', $this->hoja([['Folio', 'Equipo'], ['A1', 'cambiado']]), 'v3');
        $this->sincronizar($origen);
        $this->post(route('secciones.origenes.mapeo.aplicar-retenido', [$origen->seccion, $origen]), ['mapeo' => $mapeo->id]);
        $aplicado = Activity::query()->where('event', 'cambio_masivo_aplicado')->sole();
        $this->assertSame($this->auth(), $aplicado->causer_id);
        $this->assertSame($origen->seccion_id, $aplicado->subject_id);
        $this->assertStringNotContainsString('Administrador', $aplicado->description);
    }

    private function auth(): int
    {
        return (int) auth()->id();
    }
}
