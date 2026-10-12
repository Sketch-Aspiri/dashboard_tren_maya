<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Services\Secciones\ConsultaSeccionService;
use App\Services\Secciones\ExportacionService;
use App\Services\Secciones\NormalizadorValores;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Tabla de una sección: columnas, alta/edición/baja manual de registros,
 * búsqueda, orden y exportación.
 */
class SeccionTablaTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private function seccionPersonal(): Seccion
    {
        return $this->seccionConColumnas([
            'Nombre' => TipoColumna::Texto,
            'Edad' => TipoColumna::Numero,
            'Ingreso' => TipoColumna::Fecha,
            'Activo' => TipoColumna::Booleano,
        ]);
    }

    public function test_empty_section_explains_how_to_start(): void
    {
        $this->comoAdministrador();

        $this->get(route('secciones.show', Seccion::factory()->create()))
            ->assertOk()
            ->assertSee('Esta sección aún no tiene tabla')
            ->assertSee('Arrastra aquí Excel, Word, PDF o imágenes');
    }

    public function test_section_page_shows_rows_formatted_by_type(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $this->filaEn($seccion, ['nombre' => 'Ana López', 'edad' => 34, 'ingreso' => '2024-03-01', 'activo' => true]);

        $this->get(route('secciones.show', $seccion))
            ->assertOk()
            ->assertSee('Ana López')
            ->assertSee('01/03/2024')
            ->assertSee('Sí');
    }

    public function test_admin_adds_a_row_and_values_are_normalized_and_logged(): void
    {
        $usuario = $this->comoAdministrador();
        $seccion = $this->seccionPersonal();

        $this->post(route('secciones.filas.store', $seccion), [
            'datos' => ['nombre' => 'Luis Pérez', 'edad' => '41', 'ingreso' => '2023-07-15', 'activo' => '1', 'inventado' => 'x'],
            'seccion_id' => 999,
        ])->assertRedirect();

        $fila = SeccionFila::query()->sole();
        $this->assertSame($seccion->id, $fila->seccion_id);
        $this->assertSame(['nombre' => 'Luis Pérez', 'edad' => 41, 'ingreso' => '2023-07-15', 'activo' => true], $fila->datos);
        $this->assertStringContainsString('luis perez', $fila->texto_busqueda);
        $this->assertTrue(Activity::query()->where('subject_type', SeccionFila::class)->where('event', 'created')->where('causer_id', $usuario->id)->exists());
    }

    public function test_row_values_are_validated_by_column_type(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Edad' => TipoColumna::Numero, 'Ingreso' => TipoColumna::Fecha, 'Estatus' => TipoColumna::Lista]);
        $this->columna($seccion, 'estatus')->update(['opciones' => ['Abierto', 'Cerrado']]);

        $this->post(route('secciones.filas.store', $seccion), [
            'datos' => ['edad' => 'cuarenta', 'ingreso' => 'ayer', 'estatus' => 'Perdido'],
        ])->assertInvalid(['datos.edad', 'datos.ingreso', 'datos.estatus']);

        $this->assertDatabaseCount('seccion_filas', 0);
    }

    public function test_admin_updates_and_deletes_a_row(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $fila = $this->filaEn($seccion, ['nombre' => 'Ana', 'edad' => 30]);

        $this->put(route('secciones.filas.update', [$seccion, $fila]), ['datos' => ['edad' => '31']])->assertRedirect();
        $this->assertSame(['nombre' => 'Ana', 'edad' => 31], $fila->fresh()->datos);

        $this->delete(route('secciones.filas.destroy', [$seccion, $fila]))->assertRedirect();
        $this->assertSoftDeleted($fila);
        $this->assertTrue(Activity::query()->where('subject_id', $fila->id)->where('subject_type', SeccionFila::class)->where('event', 'deleted')->exists());
    }

    public function test_a_row_from_another_section_is_not_reachable(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $ajena = $this->filaEn(Seccion::factory()->create(), ['nombre' => 'Ajeno']);

        $this->put(route('secciones.filas.update', [$seccion, $ajena]), ['datos' => ['nombre' => 'X']])->assertNotFound();
        $this->delete(route('secciones.filas.destroy', [$seccion, $ajena]))->assertNotFound();
    }

    public function test_estacion_role_cannot_write_rows(): void
    {
        $seccion = $this->seccionPersonal();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->post(route('secciones.filas.store', $seccion), ['datos' => ['nombre' => 'X']])->assertForbidden();
    }

    public function test_search_ignores_accents_and_case(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $this->filaEn($seccion, ['nombre' => 'José Martínez']);
        $this->filaEn($seccion, ['nombre' => 'Ana López']);

        $this->get(route('secciones.show', [$seccion, 'q' => 'MARTINEZ']))
            ->assertOk()
            ->assertSee('José Martínez')
            ->assertDontSee('Ana López');
    }

    public function test_rows_can_be_sorted_numerically_by_a_column(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $this->filaEn($seccion, ['nombre' => 'Diez', 'edad' => 10]);
        $this->filaEn($seccion, ['nombre' => 'Nueve', 'edad' => 9]);
        $this->filaEn($seccion, ['nombre' => 'Cien', 'edad' => 100]);

        $this->get(route('secciones.show', [$seccion, 'sort' => 'edad', 'direction' => 'asc']))
            ->assertSeeInOrder(['Nueve', 'Diez', 'Cien']);
    }

    public function test_invalid_listing_parameters_are_rejected(): void
    {
        $this->comoAdministrador();

        $this->get(route('secciones.show', [Seccion::factory()->create(), 'per_page' => 5000]))->assertSessionHasErrors('per_page');
    }

    public function test_columns_can_be_added_renamed_retyped_and_removed(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Monto' => TipoColumna::Texto]);
        $fila = $this->filaEn($seccion, ['monto' => '1,500']);

        $this->post(route('secciones.columnas.store', $seccion), ['nombre' => 'Estatus', 'tipo' => 'lista', 'opciones_texto' => "Pagado\nPendiente\n"])
            ->assertRedirect(route('secciones.columnas.index', $seccion));
        $this->assertSame(['Pagado', 'Pendiente'], $this->columna($seccion, 'estatus')->opciones);

        $this->put(route('secciones.columnas.update', [$seccion, $this->columna($seccion, 'monto')]), ['nombre' => 'Monto pagado', 'tipo' => 'numero']);
        $this->assertSame(1500, $fila->fresh()->datos['monto']);
        $this->assertSame('Monto pagado', $this->columna($seccion, 'monto')->nombre);

        $this->delete(route('secciones.columnas.destroy', [$seccion, $this->columna($seccion, 'monto')]));
        $this->assertArrayNotHasKey('monto', $fila->fresh()->datos);
    }

    public function test_list_columns_require_options(): void
    {
        $this->comoAdministrador();

        $this->post(route('secciones.columnas.store', Seccion::factory()->create()), ['nombre' => 'Estatus', 'tipo' => 'lista'])
            ->assertInvalid(['opciones']);
    }

    public function test_table_exports_to_excel(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionPersonal();
        $this->filaEn($seccion, ['nombre' => '=HYPERLINK("http://x")', 'edad' => 5]);

        $respuesta = $this->get(route('secciones.exportar', $seccion));

        $respuesta->assertOk();
        $respuesta->assertDownload();
        $this->assertTrue(Activity::query()->where('event', 'exportacion_excel')->exists());

        $libro = (new ExportacionService(
            app(ConsultaSeccionService::class),
            app(NormalizadorValores::class),
        ))->libro($seccion, ['q' => null, 'sort' => null, 'direction' => 'asc']);
        $celda = $libro->getActiveSheet()->getCell('A2');
        $this->assertSame('=HYPERLINK("http://x")', $celda->getValue());
        $this->assertSame(DataType::TYPE_STRING, $celda->getDataType());
    }
}
