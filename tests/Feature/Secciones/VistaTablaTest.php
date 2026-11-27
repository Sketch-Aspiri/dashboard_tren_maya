<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSecciones;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Accesibilidad: en escritorio se puede cambiar la hoja de cálculo por una tabla, y la elección se recuerda. El
 * teclado y el lector de pantalla de la hoja se prueban en navegador (portal: tools/capturas/hoja.mjs).
 */
class VistaTablaTest extends TestCase
{
    use InteractsWithSecciones;
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private Seccion $seccion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seccion = Seccion::factory()->create();
        $tabla = $this->tablaConColumnas($this->seccion, 'Inventario', ['Equipo' => TipoColumna::Texto]);
        $this->filaEnTabla($tabla, ['equipo' => 'Radio portátil']);
        $this->comoAdministrador();
    }

    public function test_por_omision_se_ve_la_hoja_con_el_enlace_para_verla_como_tabla(): void
    {
        $this->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertSee('role="grid"', false)
            ->assertSee('vista=tabla', false)
            ->assertCookieMissing('hoja_vista');
    }

    public function test_al_elegir_tabla_se_muestra_la_tabla_y_se_recuerda(): void
    {
        $this->get(route('secciones.show', [$this->seccion, 'vista' => 'tabla']))
            ->assertOk()
            ->assertDontSee('role="grid"', false)
            ->assertSee('<th scope="col"', false)
            ->assertSee('vista=hoja', false)
            ->assertCookie('hoja_vista', 'tabla');

        $this->withCookie('hoja_vista', 'tabla')->get(route('secciones.show', $this->seccion))
            ->assertOk()
            ->assertDontSee('role="grid"', false);

        $this->withCookie('hoja_vista', 'tabla')->get(route('secciones.show', [$this->seccion, 'vista' => 'hoja']))
            ->assertOk()
            ->assertSee('role="grid"', false);
    }

    public function test_una_vista_desconocida_no_se_acepta(): void
    {
        $this->get(route('secciones.show', [$this->seccion, 'vista' => 'otra']))->assertSessionHasErrors('vista');
    }
}
