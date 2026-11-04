<?php

namespace Tests\Feature\Nube;

use App\Enums\EstadoOrigen;
use App\Enums\TipoColumna;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Las tablas espejo son de solo lectura en el dashboard: ni filas, ni
 * columnas, ni la tabla, ni cargas manuales; exportar sigue funcionando.
 */
class TablasSoloLecturaTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private Seccion $seccion;

    private SeccionTabla $espejo;

    private SeccionTabla $normal;

    private SeccionOrigen $origen;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seccion = Seccion::factory()->create();
        $this->origen = SeccionOrigen::forceCreate([
            'seccion_id' => $this->seccion->id,
            'tipo' => 'carpeta',
            'proveedor' => 'microsoft',
            'remoto_item_id' => 'raiz',
            'enlace_original' => 'https://1drv.ms/f/s!x',
            'nombre' => 'Rol de vacaciones',
            'alimenta_tablas' => true,
            'estado' => EstadoOrigen::Activo,
            'sincronizado_en' => now()->subMinutes(5),
        ]);
        $this->espejo = $this->tablaConColumnas($this->seccion, 'Vacaciones', ['Nombre' => TipoColumna::Texto]);
        $this->espejo->forceFill(['seccion_origen_id' => $this->origen->id])->save();
        $this->normal = $this->tablaConColumnas($this->seccion, 'Notas', ['Nota' => TipoColumna::Texto]);
    }

    public function test_an_editor_cannot_create_edit_or_delete_rows_of_a_mirror_table(): void
    {
        $this->comoAdministrador();
        $fila = $this->filaEnTabla($this->espejo, ['nombre' => 'Ana']);

        $this->post(route('secciones.filas.store', $this->seccion), ['tabla' => $this->espejo->id, 'datos' => ['nombre' => 'Luis']])->assertForbidden();
        $this->put(route('secciones.filas.update', [$this->seccion, $fila]), ['datos' => ['nombre' => 'Otro']])->assertForbidden();
        $this->delete(route('secciones.filas.destroy', [$this->seccion, $fila]))->assertForbidden();

        $this->assertSame(['nombre' => 'Ana'], $fila->fresh()->datos);
        $this->assertSame(1, SeccionFila::query()->count());
    }

    public function test_rows_of_a_normal_table_in_the_same_section_stay_editable(): void
    {
        $this->comoAdministrador();

        $this->post(route('secciones.filas.store', $this->seccion), ['tabla' => $this->normal->id, 'datos' => ['nota' => 'hola']])->assertRedirect();

        $this->assertSame(1, $this->normal->filas()->count());
    }

    public function test_an_editor_cannot_create_edit_move_or_delete_columns_of_a_mirror_table(): void
    {
        $this->comoAdministrador();
        $columna = $this->espejo->columnas()->firstOrFail();

        $this->get(route('secciones.columnas.index', [$this->seccion, 'tabla' => $this->espejo->id]))->assertForbidden();
        $this->post(route('secciones.columnas.store', $this->seccion), ['tabla' => $this->espejo->id, 'nombre' => 'Nueva', 'tipo' => 'texto'])->assertForbidden();
        $this->put(route('secciones.columnas.update', [$this->seccion, $columna]), ['nombre' => 'Renombrada', 'tipo' => 'texto'])->assertForbidden();
        $this->post(route('secciones.columnas.mover', [$this->seccion, $columna]), ['direccion' => 'abajo'])->assertForbidden();
        $this->delete(route('secciones.columnas.destroy', [$this->seccion, $columna]))->assertForbidden();

        $this->assertSame('Nombre', $columna->fresh()->nombre);
        $this->assertSame(1, SeccionColumna::query()->where('seccion_tabla_id', $this->espejo->id)->count());
    }

    public function test_a_mirror_table_cannot_be_deleted_by_hand(): void
    {
        $this->comoAdministrador();

        $this->delete(route('secciones.tablas.destroy', [$this->seccion, $this->espejo]))->assertForbidden();

        $this->assertNotNull($this->espejo->fresh());
    }

    public function test_dropping_a_document_on_a_mirror_table_is_forbidden_and_a_manual_book_import_cannot_target_it(): void
    {
        $this->comoAdministrador();
        $excel = $this->excel([['Nombre'], ['Ana'], ['Luis']], 'rol.xlsx', 'Vacaciones');

        $this->post(route('secciones.archivos.store', [$this->seccion, 'tabla' => $this->espejo->id]), ['archivos' => [$excel], 'tabla' => $this->espejo->id])->assertForbidden();

        // Una carga manual de un libro cuya hoja se llama como la tabla espejo tampoco puede escribir en ella.
        $this->post(route('secciones.archivos.store', $this->seccion), ['archivos' => [$this->excel([['Nombre'], ['Ana'], ['Luis']], 'rol2.xlsx', 'Vacaciones')]], ['Accept' => 'application/json'])->assertCreated();
        $importacion = $this->seccion->importaciones()->latest('id')->firstOrFail();

        $this->post(route('secciones.importaciones.libro.aplicar', [$this->seccion, $importacion]), [
            'modo' => 'agregar',
            'hojas' => [0 => ['incluir' => 1, 'fila_encabezado' => 0, 'mapeo' => [0 => 'nombre']]],
        ])->assertForbidden();

        $this->assertSame(0, $this->espejo->filas()->count());
    }

    public function test_exporting_a_mirror_table_to_excel_still_works(): void
    {
        $this->comoAdministrador();
        $this->filaEnTabla($this->espejo, ['nombre' => 'Ana']);

        $this->get(route('secciones.exportar', [$this->seccion, 'tabla' => $this->espejo->id]))->assertOk();
    }

    public function test_the_view_hides_edit_controls_and_shows_the_sync_badge(): void
    {
        $this->comoAdministrador();
        $this->filaEnTabla($this->espejo, ['nombre' => 'Ana']);

        $respuesta = $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->espejo->id]))->assertOk();

        $respuesta->assertSee('Sincronizada con OneDrive')
            ->assertSee('hace 5 minutos')
            ->assertDontSee('Agregar registro')
            ->assertDontSee('Arrastra aquí')
            ->assertDontSee('Eliminar hoja')
            ->assertDontSee("\$dispatch('fila-editar'", false)
            ->assertSee('Exportar a Excel');
    }

    public function test_the_normal_table_of_the_same_section_keeps_its_controls(): void
    {
        $this->comoAdministrador();

        $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->normal->id]))
            ->assertOk()
            ->assertSee('Agregar registro')
            ->assertSee('Arrastra aquí')
            // El aviso de solo lectura; la pestaña de la hoja espejo sí lleva el tooltip «Sincronizada con la nube».
            ->assertDontSee('Sincronizada con OneDrive');
    }

    public function test_unlinking_the_origin_keeps_the_tables_as_normal_editable_tables(): void
    {
        $this->comoAdministrador();
        $this->filaEnTabla($this->espejo, ['nombre' => 'Ana']);
        $archivo = SeccionArchivo::forceCreate([
            'seccion_id' => $this->seccion->id, 'seccion_origen_id' => $this->origen->id, 'nombre_original' => 'rol.xlsx',
            'ruta' => 'x/rol.xlsx', 'mime' => 'x', 'extension' => 'xlsx', 'tamano' => 1, 'remoto_id' => 'r1',
        ]);
        SeccionMapeo::query()->getModel()->forceFill([
            'seccion_origen_id' => $this->origen->id, 'seccion_archivo_id' => $archivo->id, 'hoja' => 'Vacaciones', 'seccion_tabla_id' => $this->espejo->id,
        ])->save();

        $this->delete(route('secciones.origenes.destroy', [$this->seccion, $this->origen]), ['conservar' => 1])->assertRedirect();

        $this->assertNull($this->espejo->fresh()->seccion_origen_id);
        $this->assertFalse($this->espejo->fresh()->esEspejo());
        $this->assertSame(1, $this->espejo->filas()->count());
        $this->assertSame(0, SeccionMapeo::query()->count(), 'El mapeo ya no tiene sentido sin origen.');
        $this->assertSame(1, Activity::query()->where('event', 'origen_desvinculado')->count());
        $this->post(route('secciones.filas.store', $this->seccion), ['tabla' => $this->espejo->id, 'datos' => ['nombre' => 'Luis']])->assertRedirect();
        $this->assertSame(2, $this->espejo->filas()->count());
    }

    public function test_an_estacion_user_cannot_edit_a_mirror_row_even_when_it_is_labelled_with_their_station(): void
    {
        $this->seed(RoleSeeder::class);
        $estacion = Estacion::factory()->create();
        $usuario = User::factory()->create(['estacion_id' => $estacion->id]);
        $usuario->assignRole('Estación');
        $fila = $this->filaEnTabla($this->espejo, ['nombre' => 'Ana']);
        $fila->forceFill(['estacion_id' => $estacion->id])->save();
        // Aun con permiso Editar sobre toda la sección, la tabla espejo es de solo lectura.
        $this->seccion->forceFill(['acceso_todas' => 'editar'])->save();
        $this->actingAsTwoFactorVerified($usuario);

        $this->put(route('secciones.filas.update', [$this->seccion, $fila]), ['datos' => ['nombre' => 'Otro']])->assertForbidden();
        $this->assertSame(['nombre' => 'Ana'], $fila->fresh()->datos);
    }
}
