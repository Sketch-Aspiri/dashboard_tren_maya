<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionFila;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Columnas de teléfono (formato "983 168 7870"), secciones "solo
 * documentos" y el menú compacto de subsecciones.
 */
class TelefonosYSoloDocumentosTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    public function test_phone_is_saved_formatted_when_captured_by_hand(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Nombre' => TipoColumna::Texto, 'Teléfono' => TipoColumna::Telefono]);

        $this->post(route('secciones.filas.store', $seccion), ['datos' => ['nombre' => 'Ana', 'telefono' => '983-168-7870']])
            ->assertRedirect();

        $this->assertSame('983 168 7870', SeccionFila::query()->sole()->datos['telefono']);
    }

    public function test_invalid_phone_is_rejected_on_manual_capture(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Teléfono' => TipoColumna::Telefono]);

        $this->post(route('secciones.filas.store', $seccion), ['datos' => ['telefono' => '9883-9383-8383']])
            ->assertInvalid(['datos.telefono']);
        $this->assertSame(0, SeccionFila::query()->count());
    }

    public function test_changing_a_column_to_phone_reformats_existing_values(): void
    {
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Teléfono' => TipoColumna::Texto]);
        $this->filaEn($seccion, ['telefono' => '983.168.7870']);
        $this->filaEn($seccion, ['telefono' => 'sin número']);
        $columna = $this->columna($seccion, 'telefono');

        $this->put(route('secciones.columnas.update', [$seccion, $columna]), ['nombre' => 'Teléfono', 'tipo' => 'telefono'])
            ->assertRedirect();

        $valores = SeccionFila::query()->get()->pluck('datos.telefono')->all();
        $this->assertEqualsCanonicalizing(['983 168 7870', 'sin número'], $valores);
    }

    public function test_solo_documentos_hides_the_table_part_and_does_not_import_files(): void
    {
        Storage::fake();
        $this->comoAdministrador();
        $seccion = $this->seccionConColumnas(['Nombre' => TipoColumna::Texto]);
        $seccion->update(['solo_documentos' => true]);

        $this->get(route('secciones.show', $seccion))->assertOk()
            ->assertDontSee('Agregar registro')
            ->assertDontSee('Exportar a Excel')
            ->assertDontSee('Cargas (')
            ->assertDontSee('Tabla (')
            ->assertSee('Documentos (');

        $respuesta = $this->postJson(route('secciones.archivos.store', $seccion), [
            'archivos' => [$this->excel([['Nombre'], ['Luis']])],
        ])->assertCreated();

        $this->assertNull($respuesta->json('data.redirigir'));
        $this->assertSame(0, SeccionFila::query()->count());
        $this->assertDatabaseCount('seccion_archivos', 1);
    }

    public function test_admin_toggles_solo_documentos_from_the_menu_editor(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();

        $this->put(route('secciones.update', $seccion), ['nombre' => $seccion->nombre, 'solo_documentos' => '1'])->assertRedirect();
        $this->assertTrue($seccion->fresh()->solo_documentos);

        $this->put(route('secciones.update', $seccion), ['solo_documentos' => '0'])->assertRedirect();
        $this->assertFalse($seccion->fresh()->solo_documentos);
    }

    public function test_menu_lists_only_the_first_subsections_of_the_open_branch_and_summarizes_the_rest(): void
    {
        $this->comoAdministrador();
        $raiz = Seccion::factory()->create(['nombre' => 'Estaciones']);

        foreach (range(1, 8) as $numero) {
            Seccion::factory()->create([
                'menu_grupo_id' => $raiz->menu_grupo_id,
                'parent_id' => $raiz->id,
                'orden' => $numero,
                'nombre' => "Estacion {$numero}",
            ]);
        }

        $respuesta = $this->get(route('secciones.show', $raiz))->assertOk();

        // Las tarjetas de la página también listan subsecciones: se revisa el menú por su enlace "más".
        $respuesta->assertSee('Ver 3 más…');

        // La sección abierta siempre aparece en el menú aunque quede fuera de las primeras.
        $octava = Seccion::query()->where('nombre', 'Estacion 8')->firstOrFail();
        $this->get(route('secciones.show', $octava))->assertOk()
            ->assertSee(route('secciones.show', $octava), false);
    }
}
