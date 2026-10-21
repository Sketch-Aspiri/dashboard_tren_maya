<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoImportacion;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Services\Secciones\ImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Un Excel con varias hojas se sube UNA vez: cada hoja con datos se vuelve
 * una tabla (sección hermana con el nombre de la hoja), con vista previa
 * por hoja y deshacer de toda la carga junta.
 */
class ImportacionLibroTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @param  array<string, list<list<mixed>>>  $hojas  nombre de hoja => filas
     */
    private function libro(array $hojas): UploadedFile
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

        return new UploadedFile($ruta, 'libro.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function tresHojas(): array
    {
        return [
            'Escaleras' => [['Equipo', 'Estado'], ['E-1', 'Operando'], ['E-2', 'Falla']],
            'Elevadores' => [['Equipo', 'Piso'], ['V-1', 3], ['V-2', 5], ['V-3', 7]],
            'Andenes' => [['Andén', 'Longitud'], ['A', 120], ['B', 110]],
        ];
    }

    private function cargar(Seccion $seccion, array $hojas): SeccionImportacion
    {
        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$this->libro($hojas)]])->assertCreated();

        return SeccionImportacion::query()->latest('id')->firstOrFail();
    }

    /**
     * Payload tal como lo envía la vista previa: todas las hojas, columnas nuevas.
     *
     * @param  array<int, array<string, mixed>>  $porHoja
     * @return array<string, mixed>
     */
    private function opciones(int $hojas, array $porHoja = [], string $modo = 'agregar'): array
    {
        $payload = ['modo' => $modo, 'hojas' => []];

        for ($i = 0; $i < $hojas; $i++) {
            $payload['hojas'][$i] = array_merge([
                'incluir' => 1,
                'fila_encabezado' => 0,
                'mapeo' => ['nueva', 'nueva'],
                'tipos' => ['texto', 'texto'],
                'llave' => null,
            ], $porHoja[$i] ?? []);
        }

        return $payload;
    }

    public function test_preview_of_a_multi_sheet_workbook_lists_every_sheet(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->get(route('secciones.importaciones.show', [$seccion, $importacion]))
            ->assertOk()
            ->assertSee('Escaleras')
            ->assertSee('Elevadores')
            ->assertSee('Andenes')
            ->assertSee('todas las hojas');
    }

    public function test_a_single_upload_creates_one_table_per_sheet_with_rows_and_one_audit_event(): void
    {
        $usuario = $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3))
            ->assertRedirect(route('secciones.show', $seccion));

        foreach (['Escaleras' => 2, 'Elevadores' => 3, 'Andenes' => 2] as $nombre => $filas) {
            $hija = Seccion::query()->where('nombre', $nombre)->sole();
            $this->assertSame($seccion->menu_grupo_id, $hija->menu_grupo_id);
            $this->assertSame($filas, $hija->filas()->count());
            $this->assertSame(2, $hija->columnas()->count());
        }

        $this->assertSame(['equipo', 'piso'], Seccion::query()->where('nombre', 'Elevadores')->sole()->columnas()->pluck('clave')->all());
        $this->assertSame(EstadoImportacion::Aplicada, $importacion->fresh()->estado);
        $this->assertSame(1, Activity::query()->where('event', 'importacion_aplicada')->where('causer_id', $usuario->id)->count());
        $this->assertSame(7, $importacion->fresh()->resumen['nuevas']);
    }

    public function test_empty_sheets_are_skipped(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $hojas = $this->tresHojas() + ['Vacía' => [[null]], 'Solo títulos' => [['A', 'B'], ['x', 'y']]];
        $hojas['Solo títulos'] = [['A', 'B'], [null, null]];
        $importacion = $this->cargar($seccion, $hojas);

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(count($importacion->tablas)))
            ->assertRedirect();

        $this->assertNull(Seccion::query()->where('nombre', 'Vacía')->first());
        $this->assertNull(Seccion::query()->where('nombre', 'Solo títulos')->first());
        $this->assertNotNull(Seccion::query()->where('nombre', 'Andenes')->first());
    }

    public function test_unchecked_sheets_are_not_loaded(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3, [1 => ['incluir' => 0]]))
            ->assertRedirect();

        $this->assertNull(Seccion::query()->where('nombre', 'Elevadores')->first());
        $this->assertNotNull(Seccion::query()->where('nombre', 'Escaleras')->first());
        $this->assertSame(4, SeccionFila::query()->count());
    }

    public function test_preview_calculation_writes_nothing(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->postJson(route('secciones.importaciones.libro.calcular', [$seccion, $importacion]), $this->opciones(3))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.nuevas', 7)
            ->assertJsonCount(3, 'data.hojas');

        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertSame(1, Seccion::query()->count());
    }

    public function test_sheet_with_the_name_of_an_existing_section_loads_into_it(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $existente = Seccion::factory()->create(['menu_grupo_id' => $seccion->menu_grupo_id, 'nombre' => 'Escaleras', 'slug' => 'escaleras']);
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3))->assertRedirect();

        $this->assertSame(1, Seccion::query()->where('nombre', 'Escaleras')->count());
        $this->assertSame(2, $existente->filas()->count());
    }

    public function test_undo_reverts_every_sheet_of_the_load_together(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3));

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]))->assertRedirect();

        $this->assertSame(0, SeccionFila::query()->count());
        $this->assertSame(0, Seccion::query()->whereIn('nombre', ['Escaleras', 'Elevadores', 'Andenes'])->count());
        $this->assertDatabaseCount('seccion_columnas', 0);
        $this->assertSame(EstadoImportacion::Deshecha, $importacion->fresh()->estado);
        $this->assertTrue(Activity::query()->where('event', 'importacion_deshecha')->exists());
    }

    public function test_undo_is_blocked_when_a_row_of_any_sheet_was_edited_afterwards(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3));

        $this->travel(5)->minutes();
        SeccionFila::query()->first()->update(['datos' => ['equipo' => 'Editado']]);

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]))->assertSessionHasErrors('importacion');
        $this->assertSame(7, SeccionFila::query()->count());
    }

    public function test_replace_mode_requires_confirmation(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3, [], 'reemplazar'))
            ->assertSessionHasErrors('confirma_reemplazo');
    }

    public function test_invalid_payloads_are_rejected_by_the_form_request(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());
        $url = route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]);

        $this->post($url, ['modo' => 'borrar-todo'] + $this->opciones(3))->assertSessionHasErrors('modo');
        $this->post($url, ['modo' => 'agregar'])->assertSessionHasErrors('hojas');
        $this->post($url, $this->opciones(3, [0 => ['fila_encabezado' => -1]]))->assertSessionHasErrors('hojas.0.fila_encabezado');

        config(['secciones.max_hojas_importacion' => 2]);
        $this->post($url, $this->opciones(3))->assertSessionHasErrors('hojas');
        $this->assertDatabaseCount('seccion_filas', 0);
    }

    public function test_failure_in_one_sheet_rolls_back_the_whole_load(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());

        // La hoja 2 ignora todas sus columnas: el planificador la rechaza.
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3, [2 => ['mapeo' => ['ignorar', 'ignorar']]]))
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertSame(1, Seccion::query()->count());
        $this->assertSame(EstadoImportacion::VistaPrevia, $importacion->fresh()->estado);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $seccion = Seccion::factory()->create();
        $importacion = new SeccionImportacion(['origen' => 'archivo', 'estado' => EstadoImportacion::VistaPrevia, 'tablas' => []]);
        $importacion->forceFill(['seccion_id' => $seccion->id])->save();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(1))->assertForbidden();
        $this->postJson(route('secciones.importaciones.libro.calcular', [$seccion, $importacion]), $this->opciones(1))->assertForbidden();
    }

    public function test_undo_is_blocked_when_a_later_load_was_applied_to_any_destination_section(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3));
        $this->assertTrue(app(ImportacionService::class)->puedeDeshacerse($importacion->fresh()));

        $elevadores = Seccion::query()->where('nombre', 'Elevadores')->sole();
        $posterior = new SeccionImportacion(['origen' => 'archivo', 'estado' => EstadoImportacion::Aplicada, 'tablas' => [], 'resumen' => [], 'cambios' => []]);
        $posterior->forceFill(['seccion_id' => $elevadores->id, 'aplicada_at' => now()->addMinute()])->save();

        $this->assertFalse(app(ImportacionService::class)->puedeDeshacerse($importacion->fresh()));
        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]))->assertSessionHasErrors('importacion');
        $this->assertSame(7, SeccionFila::query()->count());
    }

    public function test_word_documents_with_several_tables_keep_the_single_table_flow_by_default(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $word = new PhpWord;
        $s = $word->addSection();

        foreach ([1, 2] as $n) {
            $t = $s->addTable();
            $t->addRow();
            $t->addCell()->addText('Equipo');
            $t->addCell()->addText('Estado');
            $t->addRow();
            $t->addCell()->addText("E-$n");
            $t->addCell()->addText('Ok');
            $s->addTextBreak();
        }

        $ruta = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($ruta);
        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [new UploadedFile($ruta, 'oficio.docx', null, null, true)]])->assertCreated();
        $importacion = SeccionImportacion::query()->latest('id')->firstOrFail();
        $this->assertCount(2, $importacion->tablas);

        $this->get(route('secciones.importaciones.show', [$seccion, $importacion]))
            ->assertOk()
            ->assertDontSee('Vista previa de la carga: todas las hojas')
            ->assertSee('Cargar todas las hojas de una vez');

        $this->get(route('secciones.importaciones.show', [$seccion, $importacion, 'libro' => 1]))
            ->assertOk()
            ->assertSee('Vista previa de la carga: todas las hojas');
    }

    public function test_sheets_that_collide_on_the_same_destination_are_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, [
            'Estación' => [['Equipo', 'Estado'], ['E-1', 'Ok']],
            'Estacion' => [['Equipo', 'Estado'], ['E-2', 'Ok']],
        ]);

        $this->postJson(route('secciones.importaciones.libro.calcular', [$seccion, $importacion]), $this->opciones(2))
            ->assertUnprocessable()->assertJsonValidationErrors('hojas');
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(2))
            ->assertSessionHasErrors('hojas');

        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertSame(1, Seccion::query()->count());
    }

    public function test_sheets_resolving_to_the_same_existing_section_are_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        Seccion::factory()->create(['menu_grupo_id' => $seccion->menu_grupo_id, 'nombre' => 'Estación', 'slug' => 'estacion']);
        $importacion = $this->cargar($seccion, [
            'Estación' => [['Equipo', 'Estado'], ['E-1', 'Ok']],
            'ESTACION' => [['Equipo', 'Estado'], ['E-2', 'Ok']],
        ]);

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(2))
            ->assertSessionHasErrors('hojas');
        $this->assertDatabaseCount('seccion_filas', 0);
    }

    public function test_each_existing_destination_section_is_authorized(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        Seccion::factory()->create(['menu_grupo_id' => $seccion->menu_grupo_id, 'nombre' => 'Elevadores', 'slug' => 'elevadores']);
        $importacion = $this->cargar($seccion, $this->tresHojas());

        Gate::before(fn ($usuario, string $habilidad, array $argumentos) => $habilidad === 'gestionarDatos' && ($argumentos[0]->nombre ?? null) === 'Elevadores' ? false : null);

        $this->postJson(route('secciones.importaciones.libro.calcular', [$seccion, $importacion]), $this->opciones(3))->assertForbidden();
        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $this->opciones(3))->assertForbidden();
        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertNull(Seccion::query()->where('nombre', 'Escaleras')->first());
    }

    public function test_sheets_not_included_do_not_need_a_mapping(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $importacion = $this->cargar($seccion, $this->tresHojas());
        $payload = $this->opciones(3, [1 => ['incluir' => 0]]);
        unset($payload['hojas'][1]['mapeo'], $payload['hojas'][1]['tipos']);

        $this->post(route('secciones.importaciones.libro.aplicar', [$seccion, $importacion]), $payload)->assertRedirect(route('secciones.show', $seccion));
        $this->assertNull(Seccion::query()->where('nombre', 'Elevadores')->first());

        $incluida = $this->opciones(3);
        unset($incluida['hojas'][0]['mapeo']);
        $this->post(route('secciones.importaciones.libro.calcular', [$seccion, $importacion]), $incluida)->assertSessionHasErrors('hojas.0.mapeo');
    }

    public function test_workbook_with_more_sheets_than_the_limit_is_rejected_when_read(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        config(['secciones.max_hojas_importacion' => 2]);

        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$this->libro($this->tresHojas())]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('archivos');

        $this->assertDatabaseCount('seccion_importaciones', 0);
    }
}
