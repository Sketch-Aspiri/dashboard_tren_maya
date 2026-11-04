<?php

namespace Tests\Feature\Secciones;

use App\Enums\EstadoOrigen;
use App\Enums\TipoColumna;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionFila;
use App\Models\SeccionOrigen;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\ExportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Hoja de cálculo sobre las tablas de una sección: carga, edición por lotes,
 * formato, estructura (filas/columnas/orden), diseño (combinaciones y
 * formato de tabla), hojas y exportación con fórmulas reales.
 */
class HojaCalculoTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private const SIN_FILTROS = ['q' => null, 'sort' => null, 'direction' => 'asc'];

    private Seccion $seccion;

    private SeccionTabla $tabla;

    /** @var list<SeccionFila> */
    private array $filas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seccion = Seccion::factory()->create();
        $this->tabla = $this->tablaConColumnas($this->seccion, 'Inventario', [
            'Equipo' => TipoColumna::Texto,
            'Cantidad' => TipoColumna::Numero,
            'Estado' => TipoColumna::Lista,
            'Foto' => TipoColumna::Foto,
        ]);
        $this->tabla->columnas()->where('clave', 'estado')->update(['opciones' => json_encode(['Bueno', 'Malo'])]);
        $this->filas = [
            $this->filaEnTabla($this->tabla, ['equipo' => 'Radio', 'cantidad' => 3]),
            $this->filaEnTabla($this->tabla, ['equipo' => 'Linterna', 'cantidad' => 5]),
            $this->filaEnTabla($this->tabla, ['equipo' => 'Botiquín', 'cantidad' => 2]),
        ];
    }

    private function cuentaEstacion(?string $nivel): User
    {
        $estacion = Estacion::factory()->create();
        $usuario = $this->usuarioConRol('Estación');
        $usuario->forceFill(['estacion_id' => $estacion->id])->save();

        if ($nivel !== null) {
            $this->tabla->estacionesAprobadas()->sync([$estacion->id => ['nivel' => $nivel]]);
        }

        return $this->actingAsTwoFactorVerified($usuario->fresh());
    }

    /**
     * @param  list<array<string, mixed>>  $cambios
     */
    private function guardar(array $cambios): TestResponse
    {
        return $this->postJson(route('secciones.hoja.celdas', $this->seccion), ['tabla' => $this->tabla->id, 'cambios' => $cambios]);
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    private function columnaEnOrden(string $clave): array
    {
        return $this->tabla->filas()->getQuery()->enOrdenDeHoja()->get()->map(fn (SeccionFila $f) => $f->datos[$clave] ?? null)->all();
    }

    // ------------------------------------------------------------ carga y permisos

    public function test_guest_cannot_load_the_sheet(): void
    {
        $this->getJson(route('secciones.hoja.show', [$this->seccion, 'tabla' => $this->tabla->id]))->assertUnauthorized();
    }

    public function test_admin_loads_the_whole_sheet_in_sheet_order_with_permissions(): void
    {
        $this->comoAdministrador();

        $respuesta = $this->getJson(route('secciones.hoja.show', [$this->seccion, 'tabla' => $this->tabla->id]))->assertOk();

        $respuesta->assertJsonPath('success', true)
            ->assertJsonPath('data.permisos', ['editar' => true, 'estructura' => true])
            ->assertJsonPath('data.columnas.1.clave', 'cantidad')
            ->assertJsonPath('data.columnas.2.opciones', ['Bueno', 'Malo'])
            ->assertJsonPath('data.filas.0.datos.equipo', 'Radio')
            ->assertJsonPath('data.truncada', false);
        $this->assertCount(3, $respuesta->json('data.filas'));
    }

    public function test_station_without_permission_gets_not_found(): void
    {
        $this->cuentaEstacion(null);

        $this->getJson(route('secciones.hoja.show', [$this->seccion, 'tabla' => $this->tabla->id]))->assertNotFound();
        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'X']])->assertNotFound();
    }

    public function test_station_with_view_level_reads_but_cannot_edit(): void
    {
        $this->cuentaEstacion('ver');

        $this->getJson(route('secciones.hoja.show', [$this->seccion, 'tabla' => $this->tabla->id]))
            ->assertOk()->assertJsonPath('data.permisos', ['editar' => false, 'estructura' => false]);
        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'X']])->assertForbidden();
        $this->postJson(route('secciones.hoja.filas.insertar', $this->seccion), ['tabla' => $this->tabla->id, 'cantidad' => 1])->assertForbidden();
        $this->assertSame('Radio', $this->filas[0]->fresh()->datos['equipo']);
    }

    public function test_station_with_edit_level_edits_cells_and_it_is_logged(): void
    {
        $usuario = $this->cuentaEstacion('editar');

        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'Radio portátil']])
            ->assertOk()->assertJsonPath("data.filas.{$this->filas[0]->id}.datos.equipo", 'Radio portátil');

        $this->assertSame('Radio portátil', $this->filas[0]->fresh()->datos['equipo']);
        $this->assertSame($usuario->id, $this->filas[0]->fresh()->actualizado_por);
        $this->assertTrue(Activity::query()->where('subject_type', SeccionFila::class)->where('subject_id', $this->filas[0]->id)
            ->where('event', 'updated')->where('causer_id', $usuario->id)->exists());
    }

    public function test_station_with_edit_level_cannot_touch_the_structure(): void
    {
        $this->cuentaEstacion('editar');

        $this->postJson(route('secciones.hoja.columnas.insertar', $this->seccion), ['tabla' => $this->tabla->id])->assertForbidden();
        $columna = $this->tabla->columnas()->first();
        $this->putJson(route('secciones.hoja.columnas.actualizar', [$this->seccion, $columna]), ['ancho' => 200])->assertForbidden();
        $this->deleteJson(route('secciones.hoja.columnas.eliminar', [$this->seccion, $columna]))->assertForbidden();
        $this->post(route('secciones.tablas.store', $this->seccion), ['nombre' => 'Otra'])->assertForbidden();
        $this->assertSame(4, $this->tabla->columnas()->count());
    }

    public function test_a_mirror_sheet_is_read_only_even_for_the_zone(): void
    {
        $this->comoAdministrador();
        $origen = SeccionOrigen::forceCreate([
            'seccion_id' => $this->seccion->id, 'tipo' => 'carpeta', 'proveedor' => 'microsoft', 'remoto_item_id' => 'raiz',
            'enlace_original' => 'https://1drv.ms/f/s!x', 'nombre' => 'Inventario', 'alimenta_tablas' => true, 'estado' => EstadoOrigen::Activo,
        ]);
        $this->tabla->forceFill(['seccion_origen_id' => $origen->id])->save();

        $this->getJson(route('secciones.hoja.show', [$this->seccion, 'tabla' => $this->tabla->id]))
            ->assertOk()->assertJsonPath('data.permisos.editar', false);
        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'X']])->assertForbidden();
    }

    // ------------------------------------------------------------ validación

    public function test_values_must_fit_the_column_type_but_formulas_always_fit(): void
    {
        $this->comoAdministrador();
        $fila = $this->filas[0]->id;

        $this->guardar([['fila' => $fila, 'columna' => 'cantidad', 'valor' => 'muchos']])->assertInvalid(['cambios.0.valor']);
        $this->guardar([['fila' => $fila, 'columna' => 'estado', 'valor' => 'Regular']])->assertInvalid(['cambios.0.valor']);
        $this->guardar([['fila' => $fila, 'columna' => 'foto', 'valor' => 'x.jpg']])->assertInvalid(['cambios.0.valor']);
        $this->guardar([['fila' => $fila, 'columna' => 'no_existe', 'valor' => '1']])->assertInvalid(['cambios.0.columna']);
        $this->guardar([['fila' => $fila, 'columna' => 'cantidad', 'valor' => ['a' => 1]]])->assertInvalid(['cambios.0.valor']);

        $this->guardar([
            ['fila' => $fila, 'columna' => 'cantidad', 'valor' => '1,500'],
            ['fila' => $this->filas[1]->id, 'columna' => 'cantidad', 'valor' => '=B1*2'],
            ['fila' => $this->filas[2]->id, 'columna' => 'estado', 'valor' => 'Malo'],
        ])->assertOk();

        $this->assertSame([1500, '=B1*2', 2], $this->columnaEnOrden('cantidad'));
        $this->assertSame('Malo', $this->filas[2]->fresh()->datos['estado']);
    }

    public function test_one_invalid_cell_rejects_the_whole_batch(): void
    {
        $this->comoAdministrador();

        $this->guardar([
            ['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'Cambiado'],
            ['fila' => $this->filas[1]->id, 'columna' => 'cantidad', 'valor' => 'no'],
        ])->assertInvalid(['cambios.1.valor']);

        $this->assertSame('Radio', $this->filas[0]->fresh()->datos['equipo']);
    }

    public function test_rows_of_another_sheet_cannot_be_edited_through_this_one(): void
    {
        $this->comoAdministrador();
        $otra = $this->tablaConColumnas($this->seccion, 'Otra', ['Equipo' => TipoColumna::Texto]);
        $ajena = $this->filaEnTabla($otra, ['equipo' => 'Ajena']);

        $this->guardar([['fila' => $ajena->id, 'columna' => 'equipo', 'valor' => 'X']])->assertInvalid(['cambios']);
        $this->assertSame('Ajena', $ajena->fresh()->datos['equipo']);
    }

    public function test_cell_styles_are_whitelisted_and_can_be_cleared(): void
    {
        $this->comoAdministrador();
        $fila = $this->filas[0];

        $this->guardar([['fila' => $fila->id, 'columna' => 'equipo', 'formato' => ['color' => 'red']]])->assertInvalid(['cambios.0.formato.color']);
        $this->guardar([['fila' => $fila->id, 'columna' => 'equipo', 'formato' => ['tam' => 99]]])->assertInvalid(['cambios.0.formato.tam']);

        $this->guardar([['fila' => $fila->id, 'columna' => 'equipo', 'formato' => [
            'n' => true, 'k' => false, 'fondo' => '#FDE68A', 'alin' => 'centro', 'tam' => 14, 'css' => 'background:url(x)', 'onclick' => 'x',
        ]]])->assertOk();

        $this->assertSame(['n' => true, 'fondo' => '#fde68a', 'alin' => 'centro', 'tam' => 14], $fila->fresh()->formato['equipo']);
        $this->assertSame('Radio', $fila->fresh()->datos['equipo'], 'solo el formato cambia');

        $this->guardar([['fila' => $fila->id, 'columna' => 'equipo', 'formato' => null]])->assertOk();
        $this->assertNull($fila->fresh()->formato);
    }

    public function test_extra_fields_in_a_change_are_not_persisted(): void
    {
        $this->comoAdministrador();

        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => 'X', 'seccion_id' => 999, 'estacion_id' => 5]])->assertOk();

        $fila = $this->filas[0]->fresh();
        $this->assertSame($this->seccion->id, $fila->seccion_id);
        $this->assertNull($fila->estacion_id);
    }

    public function test_an_empty_value_clears_the_cell(): void
    {
        $this->comoAdministrador();

        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'cantidad', 'valor' => null]])->assertOk();

        $this->assertArrayNotHasKey('cantidad', $this->filas[0]->fresh()->datos);
    }

    // ------------------------------------------------------------ estructura

    public function test_rows_inserted_in_the_middle_keep_the_sheet_order_and_shift_formulas(): void
    {
        $usuario = $this->comoAdministrador();
        $this->guardar([['fila' => $this->filas[2]->id, 'columna' => 'equipo', 'valor' => '=A2&"!"']])->assertOk();
        $this->putJson(route('secciones.hoja.diseno', $this->seccion), ['tabla' => $this->tabla->id, 'combinaciones' => [['f1' => 1, 'c1' => 0, 'f2' => 2, 'c2' => 0]], 'tablas' => []])->assertOk();

        $ids = $this->postJson(route('secciones.hoja.filas.insertar', $this->seccion), ['tabla' => $this->tabla->id, 'antes_de' => $this->filas[1]->id, 'cantidad' => 2])
            ->assertCreated()->json('data.ids');

        $this->assertCount(2, $ids);
        $this->assertSame(['Radio', null, null, 'Linterna', '=A4&"!"'], $this->columnaEnOrden('equipo'));
        $this->assertSame([['f1' => 3, 'c1' => 0, 'f2' => 4, 'c2' => 0]], $this->tabla->fresh()->disenoHoja()['combinaciones']);
        $this->assertSame($usuario->id, SeccionFila::find($ids[0])->creado_por);
        $this->assertSame(2, Activity::query()->where('subject_type', SeccionFila::class)->whereIn('subject_id', $ids)->where('event', 'created')->count());
    }

    public function test_many_inserts_between_the_same_rows_renumber_the_sheet(): void
    {
        $this->comoAdministrador();

        for ($i = 0; $i < 25; $i++) {
            $this->postJson(route('secciones.hoja.filas.insertar', $this->seccion), ['tabla' => $this->tabla->id, 'antes_de' => $this->filas[1]->id, 'cantidad' => 1])->assertCreated();
        }

        $this->assertSame(array_merge(['Radio'], array_fill(0, 25, null), ['Linterna', 'Botiquín']), $this->columnaEnOrden('equipo'));
    }

    public function test_rows_can_be_appended_and_deleted_and_formulas_lose_deleted_references(): void
    {
        $this->comoAdministrador();
        $this->postJson(route('secciones.hoja.filas.insertar', $this->seccion), ['tabla' => $this->tabla->id, 'antes_de' => null, 'cantidad' => 1])->assertCreated();
        $nueva = $this->tabla->filas()->getQuery()->enOrdenDeHoja()->get()->last();
        $this->guardar([['fila' => $nueva->id, 'columna' => 'equipo', 'valor' => '=A2&A3']])->assertOk();

        $this->postJson(route('secciones.hoja.filas.eliminar', $this->seccion), ['tabla' => $this->tabla->id, 'filas' => [$this->filas[1]->id]])->assertOk();

        $this->assertSoftDeleted($this->filas[1]);
        $this->assertSame('=#¡REF!&A2', $nueva->fresh()->datos['equipo']);
        $this->assertTrue(Activity::query()->where('subject_id', $this->filas[1]->id)->where('event', 'deleted')->exists());
    }

    public function test_deleting_a_row_from_the_classic_form_also_shifts_formulas(): void
    {
        $this->comoAdministrador();
        $this->guardar([['fila' => $this->filas[2]->id, 'columna' => 'equipo', 'valor' => '=A1']])->assertOk();

        $this->delete(route('secciones.filas.destroy', [$this->seccion, $this->filas[0]]))->assertRedirect();

        $this->assertSame('=#¡REF!', $this->filas[2]->fresh()->datos['equipo']);
    }

    public function test_zone_inserts_renames_resizes_and_deletes_columns_and_formulas_follow(): void
    {
        $this->comoAdministrador();
        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => '=B2+B3']])->assertOk();

        $clave = $this->postJson(route('secciones.hoja.columnas.insertar', $this->seccion), ['tabla' => $this->tabla->id, 'antes_de' => 'cantidad'])
            ->assertCreated()->json('data.columna.clave');

        $this->assertSame(['equipo', $clave, 'cantidad', 'estado', 'foto'], $this->tabla->columnas()->pluck('clave')->all());
        $this->assertSame('=C2+C3', $this->filas[0]->fresh()->datos['equipo']);

        $columna = $this->tabla->columnas()->where('clave', $clave)->first();
        $this->putJson(route('secciones.hoja.columnas.actualizar', [$this->seccion, $columna]), ['nombre' => 'Marca', 'ancho' => 220])->assertOk();
        $this->assertSame(['Marca', 220], [$columna->fresh()->nombre, $columna->fresh()->ancho]);
        $this->putJson(route('secciones.hoja.columnas.actualizar', [$this->seccion, $columna]), ['ancho' => 5000])->assertInvalid(['ancho']);

        $this->deleteJson(route('secciones.hoja.columnas.eliminar', [$this->seccion, $columna]))->assertOk();
        $this->assertSame('=B2+B3', $this->filas[0]->fresh()->datos['equipo']);
    }

    public function test_sheet_sorts_by_a_column_with_empty_values_last(): void
    {
        $this->comoAdministrador();
        $this->filaEnTabla($this->tabla, ['equipo' => 'Sin cantidad']);

        $this->postJson(route('secciones.hoja.ordenar', $this->seccion), ['tabla' => $this->tabla->id, 'columna' => 'cantidad', 'direccion' => 'desc'])->assertOk();
        $this->assertSame(['Linterna', 'Radio', 'Botiquín', 'Sin cantidad'], $this->columnaEnOrden('equipo'));

        $this->postJson(route('secciones.hoja.ordenar', $this->seccion), ['tabla' => $this->tabla->id, 'columna' => 'equipo', 'direccion' => 'asc'])->assertOk();
        $this->assertSame(['Botiquín', 'Linterna', 'Radio', 'Sin cantidad'], $this->columnaEnOrden('equipo'));

        $this->postJson(route('secciones.hoja.ordenar', $this->seccion), ['tabla' => $this->tabla->id, 'columna' => 'no_existe', 'direccion' => 'asc'])->assertInvalid(['columna']);
    }

    public function test_sheet_with_merged_cells_cannot_be_sorted(): void
    {
        $this->comoAdministrador();
        $this->putJson(route('secciones.hoja.diseno', $this->seccion), ['tabla' => $this->tabla->id, 'combinaciones' => [['f1' => 0, 'c1' => 0, 'f2' => 0, 'c2' => 1]], 'tablas' => []])->assertOk();

        $this->postJson(route('secciones.hoja.ordenar', $this->seccion), ['tabla' => $this->tabla->id, 'columna' => 'equipo', 'direccion' => 'asc'])->assertInvalid(['columna']);
    }

    // ------------------------------------------------------------ diseño

    public function test_merges_and_table_formats_are_validated_and_logged(): void
    {
        $this->comoAdministrador();
        $url = route('secciones.hoja.diseno', $this->seccion);
        $base = ['tabla' => $this->tabla->id, 'tablas' => []];

        $this->putJson($url, $base + ['combinaciones' => [['f1' => 0, 'c1' => 0, 'f2' => 1, 'c2' => 1], ['f1' => 1, 'c1' => 1, 'f2' => 2, 'c2' => 2]]])->assertInvalid(['combinaciones']);
        $this->putJson($url, $base + ['combinaciones' => [['f1' => 0, 'c1' => 0, 'f2' => 50, 'c2' => 0]]])->assertInvalid(['diseno']);
        $this->putJson($url, $base + ['combinaciones' => [['f1' => 0, 'c1' => 0, 'f2' => 0, 'c2' => 0]]])->assertInvalid(['combinaciones']);
        $this->putJson($url, ['tabla' => $this->tabla->id, 'combinaciones' => [], 'tablas' => [['f1' => 0, 'c1' => 0, 'f2' => 2, 'c2' => 1, 'estilo' => 'rosa']]])->assertInvalid(['tablas.0.estilo']);

        $this->putJson($url, [
            'tabla' => $this->tabla->id,
            'combinaciones' => [['f1' => 0, 'c1' => 1, 'f2' => 0, 'c2' => 0]],
            'tablas' => [['f1' => 0, 'c1' => 0, 'f2' => 2, 'c2' => 1, 'estilo' => 'azul']],
        ])->assertOk()->assertJsonPath('data.diseno.combinaciones.0', ['f1' => 0, 'c1' => 0, 'f2' => 0, 'c2' => 1]);

        $this->assertSame('azul', $this->tabla->fresh()->disenoHoja()['tablas'][0]['estilo']);
        $this->assertTrue(Activity::query()->where('subject_type', SeccionTabla::class)->where('subject_id', $this->tabla->id)->where('event', 'updated')->exists());
    }

    // ------------------------------------------------------------ hojas

    public function test_zone_creates_renames_moves_and_duplicates_sheets(): void
    {
        $this->comoAdministrador();
        $this->guardar([['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'formato' => ['n' => true]]])->assertOk();

        $this->post(route('secciones.tablas.store', $this->seccion), ['nombre' => 'Resumen'])->assertRedirect();
        $resumen = $this->seccion->tablas()->where('nombre', 'Resumen')->firstOrFail();

        $this->put(route('secciones.tablas.update', [$this->seccion, $resumen]), ['nombre' => 'Resumen anual'])->assertRedirect();
        $this->post(route('secciones.tablas.mover', [$this->seccion, $resumen]), ['direccion' => 'arriba'])->assertRedirect();
        $this->assertSame(['Resumen anual', 'Inventario'], $this->seccion->tablas()->pluck('nombre')->all());

        $this->post(route('secciones.tablas.duplicar', [$this->seccion, $this->tabla]))->assertRedirect();
        $copia = $this->seccion->tablas()->where('nombre', 'Inventario (copia)')->firstOrFail();

        $this->assertSame(['Resumen anual', 'Inventario', 'Inventario (copia)'], $this->seccion->tablas()->pluck('nombre')->all());
        $this->assertSame(['equipo', 'cantidad', 'estado', 'foto'], $copia->columnas()->pluck('clave')->all());
        $filasCopia = $copia->filas()->getQuery()->enOrdenDeHoja()->get();
        $this->assertSame(['Radio', 'Linterna', 'Botiquín'], $filasCopia->map(fn ($f) => $f->datos['equipo'])->all());
        $this->assertSame(['n' => true], $filasCopia[0]->formato['equipo']);
        $this->assertSame(3, $this->tabla->filas()->count(), 'la original queda intacta');
    }

    public function test_sheet_name_is_required(): void
    {
        $this->comoAdministrador();

        $this->post(route('secciones.tablas.store', $this->seccion), ['nombre' => ''])->assertInvalid(['nombre']);
    }

    // ------------------------------------------------------------ exportación

    public function test_export_keeps_formulas_styles_merges_and_widths(): void
    {
        $this->comoAdministrador();
        $this->guardar([
            ['fila' => $this->filas[2]->id, 'columna' => 'cantidad', 'valor' => '=SUMA(B1:B2)', 'formato' => ['n' => true, 'fondo' => '#fde68a']],
            ['fila' => $this->filas[0]->id, 'columna' => 'equipo', 'valor' => '=HYPERLINK("http://x")'],
        ])->assertOk();
        $this->putJson(route('secciones.hoja.diseno', $this->seccion), ['tabla' => $this->tabla->id, 'combinaciones' => [['f1' => 1, 'c1' => 2, 'f2' => 2, 'c2' => 2]], 'tablas' => []])->assertOk();
        $this->tabla->columnas()->where('clave', 'equipo')->update(['ancho' => 210]);

        $hoja = app(ExportacionService::class)->libro($this->tabla->fresh(), self::SIN_FILTROS)->getActiveSheet();

        $this->assertSame('=SUM(B2:B3)', $hoja->getCell('B4')->getValue());
        $this->assertSame(DataType::TYPE_FORMULA, $hoja->getCell('B4')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $hoja->getCell('B2')->getDataType());
        $this->assertTrue($hoja->getStyle('B4')->getFont()->getBold());
        $this->assertSame('FDE68A', strtoupper($hoja->getStyle('B4')->getFill()->getStartColor()->getRGB()));
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('A2')->getDataType(), 'una función fuera de la lista blanca sale como texto');
        $this->assertArrayHasKey('C3:C4', $hoja->getMergeCells());
        $this->assertEqualsWithDelta(30, $hoja->getColumnDimension('A')->getWidth(), 0.01);
    }

    public function test_filtered_export_writes_formulas_as_text(): void
    {
        $this->comoAdministrador();
        $this->guardar([['fila' => $this->filas[2]->id, 'columna' => 'cantidad', 'valor' => '=SUMA(B1:B2)']])->assertOk();

        $hoja = app(ExportacionService::class)->libro($this->tabla->fresh(), ['q' => 'botiq', 'sort' => null, 'direction' => 'asc'])->getActiveSheet();

        $this->assertSame('=SUMA(B1:B2)', $hoja->getCell('B2')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $hoja->getCell('B2')->getDataType());
    }

    public function test_section_page_shows_the_sheet_on_desktop_and_the_result_list_when_searching(): void
    {
        $this->comoAdministrador();

        $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->tabla->id]))
            ->assertOk()->assertSee('hojaCalculo(', false)->assertSee('id="hoja-formula"', false);

        $this->get(route('secciones.show', [$this->seccion, 'tabla' => $this->tabla->id, 'q' => 'radio']))
            ->assertOk()->assertDontSee('hojaCalculo(', false)->assertSee('Volver a la hoja de cálculo');
    }
}
