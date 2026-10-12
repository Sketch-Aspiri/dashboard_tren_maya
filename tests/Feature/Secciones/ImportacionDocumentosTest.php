<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Arrastrar documentos: guardado privado, detección de tablas, vista
 * previa, aplicar (agregar / actualizar por llave / reemplazar) y deshacer.
 */
class ImportacionDocumentosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return list<list<mixed>>
     */
    private function plantilla(): array
    {
        return [
            ['TREN MAYA — RELACIÓN DE PERSONAL', null, null, null],
            ['No. empleado', 'Nombre', 'Estación', 'Fecha ingreso'],
            [1001, 'Ana López', 'Chetumal', '2024-01-15'],
            [1002, 'Luis Pérez', 'Tulum', '2023-06-01'],
        ];
    }

    private function subir(Seccion $seccion, UploadedFile $archivo): TestResponse
    {
        return $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$archivo]]);
    }

    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function opciones(array $cambios = []): array
    {
        return array_merge([
            'tabla' => 0,
            'fila_encabezado' => 1,
            'mapeo' => ['nueva', 'nueva', 'nueva', 'nueva'],
            'tipos' => ['numero', 'texto', 'texto', 'fecha'],
            'modo' => 'agregar',
            'llave' => null,
        ], $cambios);
    }

    public function test_dropping_an_excel_stores_it_privately_and_opens_a_preview(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $respuesta = $this->subir($seccion, $this->excel($this->plantilla(), 'personal.xlsx'));

        $importacion = SeccionImportacion::query()->sole();
        $respuesta->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.redirigir', route('secciones.importaciones.show', [$seccion, $importacion]));

        $archivo = SeccionArchivo::query()->sole();
        $this->assertSame('personal.xlsx', $archivo->nombre_original);
        $this->assertStringStartsWith("secciones/{$seccion->id}/", $archivo->ruta);
        $this->assertStringNotContainsString('personal', $archivo->ruta);
        Storage::disk('local')->assertExists($archivo->ruta);
        $this->assertStringContainsString('Ana López', (string) $archivo->texto_extraido);
        $this->assertSame(EstadoImportacion::VistaPrevia, $importacion->estado);
    }

    public function test_preview_page_suggests_header_row_types_and_key(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $importacion = SeccionImportacion::query()->sole();

        $respuesta = $this->get(route('secciones.importaciones.show', [$seccion, $importacion]));

        $respuesta->assertOk()->assertSee('No. empleado')->assertSee('Vista previa de la carga');
        $propuesta = $respuesta->viewData('propuesta');
        $this->assertSame(1, $propuesta['fila_encabezado']);
        $this->assertSame(['numero', 'texto', 'texto', 'fecha'], $propuesta['tipos']);
        $this->assertSame(0, $propuesta['llave']);
        $this->assertSame(2, $propuesta['total_filas']);
    }

    public function test_calculating_never_writes_and_applying_creates_the_table(): void
    {
        $usuario = $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $importacion = SeccionImportacion::query()->sole();

        $this->postJson(route('secciones.importaciones.calcular', [$seccion, $importacion]), $this->opciones())
            ->assertOk()
            ->assertJsonPath('data.nuevas', 2)
            ->assertJsonPath('data.columnas_nuevas', ['No. empleado', 'Nombre', 'Estación', 'Fecha ingreso']);
        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertDatabaseCount('seccion_columnas', 0);

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $this->opciones())
            ->assertRedirect(route('secciones.show', $seccion));

        $this->assertSame(['no_empleado', 'nombre', 'estacion', 'fecha_ingreso'], $seccion->columnas()->pluck('clave')->all());
        $this->assertSame(TipoColumna::Fecha, $this->columna($seccion, 'fecha_ingreso')->tipo);
        $this->assertSame(
            ['no_empleado' => 1001, 'nombre' => 'Ana López', 'estacion' => 'Chetumal', 'fecha_ingreso' => '2024-01-15'],
            SeccionFila::query()->orderBy('id')->first()->datos,
        );
        $this->assertSame(EstadoImportacion::Aplicada, $importacion->fresh()->estado);
        $this->assertTrue(Activity::query()->where('event', 'importacion_aplicada')->where('causer_id', $usuario->id)->exists());
    }

    public function test_second_document_updates_matching_rows_by_key_and_adds_new_ones(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $this->post(route('secciones.importaciones.aplicar', [$seccion, SeccionImportacion::query()->sole()]), $this->opciones());

        $this->subir($seccion, $this->excel([
            ['No. empleado', 'Nombre', 'Estación'],
            [1002, 'Luis Pérez', 'Bacalar'],
            [1003, 'Rosa Díaz', 'Limones'],
        ]));
        $segunda = SeccionImportacion::query()->latest('id')->first();
        $opciones = ['tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => ['no_empleado', 'nombre', 'estacion'], 'modo' => 'actualizar', 'llave' => 0];

        $this->postJson(route('secciones.importaciones.calcular', [$seccion, $segunda]), $opciones)
            ->assertJsonPath('data.nuevas', 1)
            ->assertJsonPath('data.actualizadas', 1)
            ->assertJsonPath('data.no_vienen', 1);

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $segunda]), $opciones);

        $porEmpleado = SeccionFila::query()->get()->keyBy(fn ($fila) => $fila->datos['no_empleado']);
        $this->assertCount(3, $porEmpleado);
        $this->assertSame('Bacalar', $porEmpleado[1002]->datos['estacion']);
        $this->assertSame('2023-06-01', $porEmpleado[1002]->datos['fecha_ingreso']);
        $this->assertSame('Chetumal', $porEmpleado[1001]->datos['estacion']);
    }

    public function test_update_mode_requires_a_key_column(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));

        $this->postJson(route('secciones.importaciones.calcular', [$seccion, SeccionImportacion::query()->sole()]), $this->opciones(['modo' => 'actualizar']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('llave');
    }

    public function test_replace_mode_swaps_the_whole_table_and_undo_restores_it(): void
    {
        $usuario = $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Folio' => TipoColumna::Texto, 'Estatus' => TipoColumna::Texto]);
        $original = $this->filaEn($seccion, ['folio' => 'A-1', 'estatus' => 'Abierto']);

        $this->subir($seccion, $this->excel([['Folio', 'Estatus'], ['B-1', 'Nuevo'], ['B-2', 'Nuevo']]));
        $importacion = SeccionImportacion::query()->sole();
        $opciones = ['tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => ['folio', 'estatus'], 'modo' => 'reemplazar'];

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $opciones);
        $this->assertSoftDeleted($original);
        $this->assertSame(2, SeccionFila::query()->count());

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]))->assertRedirect();

        $this->assertNotSoftDeleted($original);
        $this->assertSame(['A-1'], SeccionFila::query()->get()->pluck('datos.folio')->all());
        $this->assertSame(EstadoImportacion::Deshecha, $importacion->fresh()->estado);
        $this->assertTrue(Activity::query()->where('event', 'importacion_deshecha')->where('causer_id', $usuario->id)->exists());
    }

    public function test_undo_removes_columns_the_import_created(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $importacion = SeccionImportacion::query()->sole();
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $this->opciones());

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]));

        $this->assertDatabaseCount('seccion_filas', 0);
        $this->assertDatabaseCount('seccion_columnas', 0);
    }

    public function test_only_the_latest_applied_load_can_be_undone(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $primera = SeccionImportacion::query()->sole();
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $primera]), $this->opciones());

        $this->travel(1)->minutes();
        $this->subir($seccion, $this->excel([['No. empleado'], [2000]]));
        $segunda = SeccionImportacion::query()->latest('id')->first();
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $segunda]), ['tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => ['no_empleado'], 'modo' => 'agregar']);

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $primera]))->assertSessionHasErrors('importacion');
        $this->assertSame(3, SeccionFila::query()->count());
    }

    public function test_an_applied_load_cannot_be_applied_twice(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));
        $importacion = SeccionImportacion::query()->sole();

        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $this->opciones());
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), $this->opciones())->assertSessionHasErrors('importacion');

        $this->assertSame(2, SeccionFila::query()->count());
    }

    public function test_discarding_a_preview_keeps_the_document(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, $this->excel($this->plantilla()));

        $this->delete(route('secciones.importaciones.destroy', [$seccion, SeccionImportacion::query()->sole()]))->assertRedirect();

        $this->assertDatabaseCount('seccion_importaciones', 0);
        $this->assertDatabaseCount('seccion_archivos', 1);
    }

    public function test_csv_and_word_tables_are_detected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $csv = UploadedFile::fake()->createWithContent('directorio.csv', "Nombre;Extensión\nCaseta;1203\nZonal;1100\n");
        $this->subir($seccion, $csv)->assertCreated();

        $word = new PhpWord;
        $tabla = $word->addSection()->addTable();
        foreach ([['Equipo', 'Estatus'], ['Escalera 1', 'Operando'], ['Escalera 2', 'Falla']] as $renglon) {
            $tabla->addRow();
            foreach ($renglon as $valor) {
                $tabla->addCell()->addText($valor);
            }
        }
        $ruta = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($word, 'Word2007')->save($ruta);
        $this->subir($seccion, new UploadedFile($ruta, 'equipos.docx', null, null, true))->assertCreated();

        $tablas = SeccionImportacion::query()->orderBy('id')->get()->pluck('tablas');
        $this->assertSame(['Nombre', 'Extensión'], $tablas[0][0]['filas'][0]);
        $this->assertSame(['Escalera 2', 'Falla'], $tablas[1][0]['filas'][2]);
    }

    public function test_a_document_without_tables_is_only_stored(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $this->subir($seccion, UploadedFile::fake()->image('foto.jpg'))
            ->assertCreated()
            ->assertJsonPath('data.redirigir', null)
            ->assertJsonPath('data.archivos.0.url_importacion', null);

        $this->assertDatabaseCount('seccion_importaciones', 0);
    }

    public function test_disallowed_or_disguised_files_are_rejected(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $this->subir($seccion, UploadedFile::fake()->create('script.php', 1, 'text/x-php'))->assertJsonValidationErrors('archivos.0');

        // Archivo real (no fake: el fake reporta el MIME según el nombre) con
        // una página HTML disfrazada de PDF.
        $ruta = tempnam(sys_get_temp_dir(), 'disfraz');
        file_put_contents($ruta, '<!DOCTYPE html><html><head><title>x</title></head><body><script>document.title = 1;</script></body></html>');
        $this->subir($seccion, new UploadedFile($ruta, 'reporte.pdf', null, null, true))
            ->assertJsonValidationErrors('archivos.0');

        $this->assertDatabaseCount('seccion_archivos', 0);
    }

    public function test_oversized_files_are_rejected(): void
    {
        config(['secciones.max_archivo_kb' => 10]);
        $this->comoAdministrador();

        $this->subir(Seccion::factory()->create(), UploadedFile::fake()->create('grande.pdf', 20, 'application/pdf'))
            ->assertJsonValidationErrors('archivos.0');
    }

    public function test_row_attachments_are_stored_without_importing(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Folio' => TipoColumna::Texto]);
        $fila = $this->filaEn($seccion, ['folio' => 'A-1']);

        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$this->excel($this->plantilla())], 'seccion_fila_id' => $fila->id])
            ->assertCreated();

        $this->assertSame($fila->id, SeccionArchivo::query()->sole()->seccion_fila_id);
        $this->assertDatabaseCount('seccion_importaciones', 0);
    }

    public function test_documents_are_downloaded_only_through_the_authorized_route(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, UploadedFile::fake()->image('plano.png'));
        $archivo = SeccionArchivo::query()->sole();

        $this->get(route('secciones.archivos.show', [$seccion, $archivo]))->assertOk()->assertDownload('plano.png');
        $this->get(route('secciones.archivos.show', [$seccion, $archivo, 'ver' => 1]))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('secciones.archivos.show', [Seccion::factory()->create(), $archivo]))->assertNotFound();
        $this->assertTrue(Activity::query()->where('event', 'documento_descargado')->exists());

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));
        $this->get(route('secciones.archivos.show', [$seccion, $archivo]))->assertForbidden();
    }

    public function test_deleting_a_document_is_soft_and_logged(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->subir($seccion, UploadedFile::fake()->image('plano.png'));
        $archivo = SeccionArchivo::query()->sole();

        $this->delete(route('secciones.archivos.destroy', [$seccion, $archivo]))->assertRedirect();

        $this->assertSoftDeleted($archivo);
        Storage::disk('local')->assertExists($archivo->ruta);
        $this->assertTrue(Activity::query()->where('subject_type', SeccionArchivo::class)->where('event', 'deleted')->exists());
    }
}
