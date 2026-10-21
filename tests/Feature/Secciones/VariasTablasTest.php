<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionImportacion;
use App\Models\SeccionTabla;
use App\Services\Secciones\ExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Una sección puede tener varias tablas (hojas de un libro, p. ej.): cada una
 * con sus columnas y filas, elegida con ?tabla=<id>; sin él, la primera.
 */
class VariasTablasTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const SIN_FILTROS = ['q' => null, 'sort' => null, 'direction' => 'asc'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Sección "Rol horarios" con dos tablas de columnas distintas.
     *
     * @return array{0: Seccion, 1: SeccionTabla, 2: SeccionTabla}
     */
    private function seccionConDosTablas(): array
    {
        $seccion = Seccion::factory()->create();
        $playa = $this->tablaConColumnas($seccion, '24_Playa del Carmen', ['NO' => TipoColumna::Texto, 'Empleado' => TipoColumna::Texto]);
        $tulum = $this->tablaConColumnas($seccion, '25_Tulum', ['NO.' => TipoColumna::Texto, 'Turno' => TipoColumna::Texto]);

        foreach (['Ana', 'Beto', 'Carla'] as $i => $nombre) {
            $this->filaEnTabla($playa, ['no' => $i + 1, 'empleado' => $nombre]);
        }
        foreach (['Matutino', 'Nocturno'] as $i => $turno) {
            $this->filaEnTabla($tulum, ['no' => $i + 1, 'turno' => $turno]);
        }

        return [$seccion, $playa, $tulum];
    }

    public function test_new_sections_are_born_with_a_default_table(): void
    {
        $this->comoAdministrador();
        $grupo = Seccion::factory()->create()->grupo;

        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => 'Rol horarios'])->assertRedirect();

        $seccion = Seccion::query()->where('nombre', 'Rol horarios')->sole();
        $this->assertSame(['Principal'], $seccion->tablas()->pluck('nombre')->all());
    }

    public function test_active_table_is_the_requested_one_the_first_or_a_new_default(): void
    {
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->assertTrue($seccion->tablaActiva($tulum->id)->is($tulum));
        $this->assertTrue($seccion->tablaActiva()->is($playa));
        $this->assertTrue($seccion->tablaActiva(999999)->is($playa), 'un id inexistente cae en la primera');

        $otra = Seccion::factory()->create();
        $this->assertTrue($otra->tablaActiva($tulum->id)->seccion->is($otra), 'una tabla de otra sección no se concede');
        $this->assertSame(['Principal'], $otra->tablas()->pluck('nombre')->all());
        $this->assertTrue($otra->tablaActiva()->is($otra->tablaActiva()), 'no duplica la tabla por defecto');
        $this->assertSame(1, $otra->tablas()->count());
    }

    public function test_subtabs_show_every_table_with_its_row_count_only_when_there_are_several(): void
    {
        $this->comoAdministrador();
        [$seccion] = $this->seccionConDosTablas();

        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertSee('Tablas de la sección')
            ->assertSee('24_Playa del Carmen (3)')
            ->assertSee('25_Tulum (2)');

        $una = $this->seccionConColumnas(['Empleado' => TipoColumna::Texto]);
        $this->get(route('secciones.show', $una))->assertOk()->assertDontSee('Tablas de la sección');
    }

    public function test_each_table_shows_only_its_own_columns_and_rows(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->get(route('secciones.show', $seccion))
            ->assertOk()->assertSee('Ana')->assertSee('Carla')->assertDontSee('Nocturno');

        $this->get(route('secciones.show', [$seccion, 'tabla' => $tulum->id]))
            ->assertOk()->assertSee('Nocturno')->assertSee('NO.')->assertDontSee('Carla')->assertDontSee('Empleado');
    }

    public function test_the_table_is_kept_in_search_sort_pagination_and_export_links(): void
    {
        $this->comoAdministrador();
        [$seccion, , $tulum] = $this->seccionConDosTablas();

        $html = $this->get(route('secciones.show', [$seccion, 'tabla' => $tulum->id, 'q' => 'noct']))->assertOk()->getContent();

        $this->assertStringContainsString('name="tabla" value="'.$tulum->id.'"', $html);
        $this->assertStringContainsString('tabla='.$tulum->id.'&amp;q=noct&amp;sort=turno', $html);
        $this->assertStringContainsString(route('secciones.exportar', [$seccion, 'q' => 'noct', 'tabla' => $tulum->id]), htmlspecialchars_decode($html));
        $this->assertStringContainsString('Nocturno', $html);
    }

    public function test_a_table_of_another_section_or_unknown_is_rejected(): void
    {
        $this->comoAdministrador();
        [$seccion] = $this->seccionConDosTablas();
        [, , $ajena] = $this->seccionConDosTablas();

        $this->get(route('secciones.show', [$seccion, 'tabla' => $ajena->id]))->assertSessionHasErrors('tabla');
        $this->get(route('secciones.exportar', [$seccion, 'tabla' => $ajena->id]))->assertSessionHasErrors('tabla');
        $this->get(route('secciones.columnas.index', [$seccion, 'tabla' => 999999]))->assertSessionHasErrors('tabla');
        $this->post(route('secciones.filas.store', $seccion), ['tabla' => $ajena->id])->assertSessionHasErrors('tabla');
        $this->assertSame(0, SeccionFila::query()->where('seccion_id', $seccion->id)->where('seccion_tabla_id', $ajena->id)->count());
    }

    public function test_export_downloads_one_table_per_file_and_logs_which(): void
    {
        $usuario = $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $respuesta = $this->get(route('secciones.exportar', [$seccion, 'tabla' => $tulum->id]))->assertOk();
        $this->assertStringContainsString('25-tulum', $respuesta->headers->get('content-disposition'));

        $hoja = app(ExportacionService::class)->libro($tulum, self::SIN_FILTROS)->getActiveSheet();
        $this->assertSame('25_Tulum', $hoja->getTitle());
        $this->assertSame(['NO.', 'Turno'], [$hoja->getCell('A1')->getValue(), $hoja->getCell('B1')->getValue()]);
        $this->assertSame(3, $hoja->getHighestRow());

        $otra = app(ExportacionService::class)->libro($playa, self::SIN_FILTROS)->getActiveSheet();
        $this->assertSame(4, $otra->getHighestRow());

        $evento = Activity::query()->where('event', 'exportacion_excel')->where('causer_id', $usuario->id)->sole();
        $this->assertSame($tulum->id, $evento->getExtraProperty('tabla_id'));
    }

    public function test_a_row_is_created_in_the_chosen_table_with_its_own_columns(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->post(route('secciones.filas.store', $seccion), ['tabla' => $tulum->id, 'datos' => ['no' => '9', 'turno' => 'Mixto', 'empleado' => 'ignorado']])
            ->assertSessionHasNoErrors();

        $fila = SeccionFila::query()->where('seccion_tabla_id', $tulum->id)->latest('id')->firstOrFail();
        $this->assertSame($seccion->id, $fila->seccion_id);
        $this->assertSame('Mixto', $fila->datos['turno']);
        $this->assertArrayNotHasKey('empleado', $fila->datos, 'solo se guardan columnas de la tabla elegida');
        $this->assertSame(3, $tulum->filas()->count());
        $this->assertSame(3, $playa->filas()->count());

        // Sin ?tabla se usa la primera.
        $this->post(route('secciones.filas.store', $seccion), ['datos' => ['empleado' => 'Dora']])->assertSessionHasNoErrors();
        $this->assertSame(4, $playa->filas()->count());
    }

    public function test_editing_a_row_validates_against_the_columns_of_its_table(): void
    {
        $this->comoAdministrador();
        [$seccion, , $tulum] = $this->seccionConDosTablas();
        $tulum->columnas()->create(['seccion_id' => $seccion->id, 'nombre' => 'Horas', 'clave' => 'horas', 'tipo' => TipoColumna::Numero, 'orden' => 3]);
        $fila = $tulum->filas()->first();

        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['horas' => 'abc']])->assertSessionHasErrors('datos.horas');
        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['horas' => '8', 'turno' => 'Vespertino']])->assertSessionHasNoErrors();

        $this->assertSame('Vespertino', $fila->fresh()->datos['turno']);
        $this->assertEquals(8, $fila->fresh()->datos['horas']);
    }

    public function test_two_tables_can_have_a_column_with_the_same_key(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->post(route('secciones.columnas.store', $seccion), ['tabla' => $playa->id, 'nombre' => 'Turno', 'tipo' => 'texto'])->assertSessionHasNoErrors();
        $this->post(route('secciones.columnas.store', $seccion), ['tabla' => $playa->id, 'nombre' => 'Turno', 'tipo' => 'texto'])->assertSessionHasNoErrors();

        $this->assertSame(['no', 'empleado', 'turno', 'turno_2'], $playa->columnas()->pluck('clave')->all());
        $this->assertSame(['no', 'turno'], $tulum->columnas()->pluck('clave')->all(), 'la clave "turno" ya existía en la otra tabla sin chocar');
    }

    public function test_columns_screen_edits_the_chosen_table_and_returns_to_it(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();

        $this->get(route('secciones.columnas.index', [$seccion, 'tabla' => $tulum->id]))
            ->assertOk()->assertSee('Turno')->assertDontSee('Empleado');

        $this->post(route('secciones.columnas.store', $seccion), ['tabla' => $tulum->id, 'nombre' => 'Lugar', 'tipo' => 'texto'])
            ->assertRedirect(route('secciones.columnas.index', [$seccion, 'tabla' => $tulum->id]));
        $this->assertContains('lugar', $tulum->columnas()->pluck('clave')->all());
        $this->assertNotContains('lugar', $playa->columnas()->pluck('clave')->all());

        $columna = $tulum->columnas()->where('clave', 'turno')->sole();
        $this->delete(route('secciones.columnas.destroy', [$seccion, $columna]))
            ->assertRedirect(route('secciones.columnas.index', [$seccion, 'tabla' => $tulum->id]));
        $this->assertArrayNotHasKey('turno', $tulum->filas()->first()->datos, 'quitar la columna limpia solo las filas de su tabla');
        $this->assertSame('Ana', $playa->filas()->first()->datos['empleado']);
    }

    public function test_deleting_a_column_only_touches_rows_of_its_own_table(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();
        $this->filaEnTabla($playa, ['no' => 7, 'turno' => 'no debe tocarse']);

        $this->delete(route('secciones.columnas.destroy', [$seccion, $tulum->columnas()->where('clave', 'turno')->sole()]));

        $this->assertSame('no debe tocarse', $playa->filas()->latest('id')->first()->datos['turno']);
    }

    public function test_roles_without_access_cannot_use_the_table_parameter(): void
    {
        [$seccion, , $tulum] = $this->seccionConDosTablas();
        $fila = $tulum->filas()->first();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->get(route('secciones.show', [$seccion, 'tabla' => $tulum->id]))->assertForbidden();
        $this->get(route('secciones.exportar', [$seccion, 'tabla' => $tulum->id]))->assertForbidden();
        $this->get(route('secciones.columnas.index', [$seccion, 'tabla' => $tulum->id]))->assertForbidden();
        $this->post(route('secciones.filas.store', $seccion), ['tabla' => $tulum->id, 'datos' => ['turno' => 'X']])->assertForbidden();
        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['turno' => 'X']])->assertForbidden();
        $this->post(route('secciones.columnas.store', $seccion), ['tabla' => $tulum->id, 'nombre' => 'X', 'tipo' => 'texto'])->assertForbidden();
        $this->assertSame('Matutino', $fila->fresh()->datos['turno']);
    }

    public function test_a_row_or_column_cannot_be_reached_through_another_section(): void
    {
        $this->comoAdministrador();
        [, , $tulum] = $this->seccionConDosTablas();
        $otra = Seccion::factory()->create();

        $this->put(route('secciones.filas.update', [$otra, $tulum->filas()->first()]), ['datos' => ['turno' => 'X']])->assertNotFound();
        $this->delete(route('secciones.columnas.destroy', [$otra, $tulum->columnas()->first()]))->assertNotFound();
    }

    public function test_a_single_table_upload_goes_to_the_chosen_table_or_else_the_first(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();
        $archivo = fn () => $this->excel([['NO.', 'Turno'], ['1', 'Vespertino'], ['2', 'Mixto']]);

        $this->postJson(route('secciones.archivos.store', [$seccion, 'tabla' => $tulum->id]), ['archivos' => [$archivo()]])->assertCreated();
        $importacion = SeccionImportacion::query()->latest('id')->firstOrFail();
        $this->assertSame($tulum->id, $importacion->seccion_tabla_id);

        $this->get(route('secciones.importaciones.show', [$seccion, $importacion]))->assertOk()->assertSee('Se carga en la tabla:')->assertSee('25_Tulum');
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), [
            'tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => ['no', 'turno'], 'modo' => 'agregar',
        ])->assertRedirect(route('secciones.show', [$seccion, 'tabla' => $tulum->id]));

        $this->assertSame(4, $tulum->filas()->count());
        $this->assertSame(3, $playa->filas()->count());
        $this->assertSame($tulum->id, $importacion->fresh()->resumen['tabla_id']);

        // Sin tabla elegida: la primera de la sección.
        $this->postJson(route('secciones.archivos.store', $seccion), ['archivos' => [$archivo()]])->assertCreated();
        $segunda = SeccionImportacion::query()->latest('id')->firstOrFail();
        $this->assertNull($segunda->seccion_tabla_id);
        $this->get(route('secciones.importaciones.show', [$seccion, $segunda]))->assertOk()->assertSee('24_Playa del Carmen');
    }

    public function test_undoing_a_single_table_upload_only_touches_that_table(): void
    {
        $this->comoAdministrador();
        [$seccion, $playa, $tulum] = $this->seccionConDosTablas();
        $this->postJson(route('secciones.archivos.store', [$seccion, 'tabla' => $tulum->id]), ['archivos' => [$this->excel([['NO.', 'Turno'], ['5', 'Extra']])]])->assertCreated();
        $importacion = SeccionImportacion::query()->latest('id')->firstOrFail();
        $this->post(route('secciones.importaciones.aplicar', [$seccion, $importacion]), [
            'tabla' => 0, 'fila_encabezado' => 0, 'mapeo' => ['no', 'turno'], 'modo' => 'agregar',
        ])->assertRedirect();
        $this->assertSame(3, $tulum->filas()->count());

        $this->post(route('secciones.importaciones.deshacer', [$seccion, $importacion]))->assertRedirect();

        $this->assertSame(2, $tulum->filas()->count());
        $this->assertSame(3, $playa->filas()->count());
        $this->assertSame(2, $seccion->tablas()->count(), 'una carga de una sola tabla no crea ni quita tablas');
    }
}
