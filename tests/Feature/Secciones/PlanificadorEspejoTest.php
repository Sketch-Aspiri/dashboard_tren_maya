<?php

namespace Tests\Feature\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionImportacion;
use App\Models\SeccionMapeo;
use App\Models\SeccionTabla;
use App\Services\Secciones\ImportacionService;
use App\Services\Secciones\OpcionesImportacion;
use App\Services\Secciones\PlanificadorImportacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Modo Espejo del planificador y mapeo guardado en la propuesta: la tabla
 * queda igual al Excel (actualiza, agrega, archiva) sin adivinar nada.
 */
class PlanificadorEspejoTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private SeccionTabla $tabla;

    protected function setUp(): void
    {
        parent::setUp();

        $seccion = Seccion::factory()->create();
        $this->tabla = $this->tablaConColumnas($seccion, 'Equipos', ['Folio' => TipoColumna::Texto, 'Equipo' => TipoColumna::Texto]);
    }

    private function opciones(?int $llave): OpcionesImportacion
    {
        return new OpcionesImportacion(0, 0, [0 => 'folio', 1 => 'equipo'], [], ModoImportacion::Espejo, $llave);
    }

    /**
     * @param  list<list<mixed>>  $filas
     * @return array<string, mixed>
     */
    private function planear(array $filas, ?int $llave): array
    {
        return app(PlanificadorImportacion::class)->planear($this->tabla, [['nombre' => 'Equipos', 'filas' => array_merge([['Folio', 'Equipo']], $filas)]], $this->opciones($llave));
    }

    public function test_mirror_with_key_updates_adds_and_archives_rows_that_no_longer_come(): void
    {
        $a = $this->filaEnTabla($this->tabla, ['folio' => 'A1', 'equipo' => 'uno']);
        $b = $this->filaEnTabla($this->tabla, ['folio' => 'B2', 'equipo' => 'dos']);
        $c = $this->filaEnTabla($this->tabla, ['folio' => 'C3', 'equipo' => 'tres']);

        $plan = $this->planear([['A1', 'uno cambiado'], ['B2', null], ['D4', 'cuatro']], 0);

        $this->assertSame(['equipo' => 'uno cambiado', 'folio' => 'A1'], $this->ordenado($plan['actualizadas'][$a->id]['despues']));
        $this->assertNull($plan['actualizadas'][$b->id]['despues']['equipo'], 'En un espejo una celda vacía sí borra el valor.');
        $this->assertCount(1, $plan['nuevas']);
        $this->assertSame([$c->id], $plan['eliminadas']);
    }

    public function test_mirror_without_key_replaces_everything(): void
    {
        $a = $this->filaEnTabla($this->tabla, ['folio' => 'A1', 'equipo' => 'uno']);
        $b = $this->filaEnTabla($this->tabla, ['folio' => 'B2', 'equipo' => 'dos']);

        $plan = $this->planear([['Z9', 'nuevo']], null);

        $this->assertCount(1, $plan['nuevas']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $plan['eliminadas']);
    }

    public function test_mirror_with_key_reports_unchanged_rows(): void
    {
        $this->filaEnTabla($this->tabla, ['folio' => 'A1', 'equipo' => 'uno']);

        $plan = $this->planear([['A1', 'uno']], 0);

        $this->assertSame(1, $plan['sin_cambios']);
        $this->assertSame([], $plan['actualizadas']);
        $this->assertSame([], $plan['eliminadas']);
    }

    public function test_proposal_with_a_saved_mapping_fixes_header_row_columns_and_key_instead_of_guessing(): void
    {
        $importaciones = app(ImportacionService::class);
        $mapeo = new SeccionMapeo;
        $mapeo->forceFill([
            'fila_encabezado' => 1,
            'columnas' => [
                ['encabezado_normalizado' => 'folio', 'clave' => 'folio'],
                ['encabezado_normalizado' => 'equipo', 'clave' => 'equipo'],
            ],
            'llave_clave' => 'folio',
        ]);
        $importacion = new SeccionImportacion(['tablas' => [['nombre' => 'Equipos', 'filas' => [
            ['Reporte de equipos', null, null],
            ['Equipo', 'Folio', 'Notas'],
            ['uno', 'A1', 'x'],
        ]]]]);

        $propuesta = $importaciones->propuesta($importacion, 0, null, $this->tabla, $mapeo);

        $this->assertSame(1, $propuesta['fila_encabezado']);
        $this->assertSame([0 => 'equipo', 1 => 'folio', 2 => 'nueva'], $propuesta['mapeo'], 'La columna nueva se crea; las mapeadas no se adivinan.');
        $this->assertSame(1, $propuesta['llave']);
        $this->assertSame(ModoImportacion::Espejo->value, $propuesta['modo']);
    }

    public function test_proposal_keeps_a_column_ignored_at_confirmation_ignored(): void
    {
        $mapeo = new SeccionMapeo;
        $mapeo->forceFill([
            'fila_encabezado' => 0,
            'columnas' => [
                ['encabezado_normalizado' => 'folio', 'clave' => 'folio'],
                ['encabezado_normalizado' => 'notas', 'clave' => SeccionMapeo::IGNORAR],
            ],
            'llave_clave' => null,
        ]);
        $importacion = new SeccionImportacion(['tablas' => [['nombre' => 'Equipos', 'filas' => [['Folio', 'Notas'], ['A1', 'x']]]]]);

        $propuesta = app(ImportacionService::class)->propuesta($importacion, 0, null, $this->tabla, $mapeo);

        $this->assertSame([0 => 'folio', 1 => 'ignorar'], $propuesta['mapeo']);
        $this->assertNull($propuesta['llave']);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function ordenado(array $datos): array
    {
        ksort($datos);

        return $datos;
    }
}
