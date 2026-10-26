<?php

namespace Tests\Feature\Secciones;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Subsecciones tipo carpetas (Sección > Estación > Persona…).
 */
class SubseccionesTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private function grupo(string $nombre = 'RR.HH.'): MenuGrupo
    {
        return MenuGrupo::query()->create(['nombre' => $nombre, 'slug' => str($nombre)->slug()->value(), 'orden' => 1]);
    }

    /** @return array{0: Seccion, 1: Seccion, 2: Seccion} raíz > hija > nieta */
    private function cadena(): array
    {
        $grupo = $this->grupo();
        $raiz = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'nombre' => 'Uniformes']);
        $hija = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'parent_id' => $raiz->id, 'nombre' => 'Estación Cancún']);
        $nieta = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'parent_id' => $hija->id, 'nombre' => 'Juan Pérez']);

        return [$raiz, $hija, $nieta];
    }

    public function test_admin_creates_a_subsection_that_inherits_the_parent_group(): void
    {
        $usuario = $this->comoAdministrador();
        [$raiz] = $this->cadena();

        $this->post(route('secciones.store'), ['parent_id' => $raiz->id, 'nombre' => 'Estación Tulum'])
            ->assertRedirect();

        $sub = Seccion::query()->where('nombre', 'Estación Tulum')->firstOrFail();
        $this->assertSame($raiz->id, $sub->parent_id);
        $this->assertSame($raiz->menu_grupo_id, $sub->menu_grupo_id);
        $this->assertSame(2, $sub->orden);
        $this->assertTrue($sub->tablas()->exists());
        $this->assertTrue(Activity::query()->where('subject_type', Seccion::class)->where('subject_id', $sub->id)
            ->where('event', 'created')->where('causer_id', $usuario->id)->exists());
    }

    public function test_subsection_requires_a_group_or_an_existing_parent(): void
    {
        $this->comoAdministrador();

        $this->post(route('secciones.store'), ['nombre' => 'Huérfana'])->assertInvalid(['menu_grupo_id']);
        $this->post(route('secciones.store'), ['parent_id' => 9999, 'nombre' => 'Huérfana'])->assertInvalid(['parent_id']);
    }

    public function test_depth_is_limited(): void
    {
        $this->comoAdministrador();
        $grupo = $this->grupo();
        $actual = Seccion::factory()->create(['menu_grupo_id' => $grupo->id]);

        for ($nivel = 2; $nivel <= Seccion::PROFUNDIDAD_MAXIMA; $nivel++) {
            $actual = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'parent_id' => $actual->id]);
        }

        $this->post(route('secciones.store'), ['parent_id' => $actual->id, 'nombre' => 'Demasiado profunda'])
            ->assertInvalid(['parent_id']);
        $this->assertDatabaseMissing('secciones', ['nombre' => 'Demasiado profunda']);
    }

    public function test_section_page_shows_breadcrumb_and_subsections(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija, $nieta] = $this->cadena();

        $this->get(route('secciones.show', $raiz))->assertOk()->assertSee('Estación Cancún');
        $this->get(route('secciones.show', $nieta))->assertOk()
            ->assertSee(route('secciones.show', $raiz), false)
            ->assertSee(route('secciones.show', $hija), false);
    }

    public function test_navigation_expands_only_the_open_branch_but_search_finds_everything(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija, $nieta] = $this->cadena();

        $cerrada = $this->get(route('dashboard'))->assertOk();
        $cerrada->assertSee('Uniformes');
        // El buscador recibe los items como JSON (unicode escapado).
        $this->assertStringContainsString(
            substr(json_encode('Uniformes › Estación Cancún › Juan Pérez'), 1, -1),
            html_entity_decode($cerrada->getContent()),
        );
        $cerrada->assertDontSee('>Juan Pérez<', false);

        $abierta = $this->get(route('secciones.show', $hija))->assertOk();
        $abierta->assertSee('Juan Pérez');
    }

    public function test_hiding_a_parent_hides_its_subsections_from_the_menu(): void
    {
        $this->comoAdministrador();
        [$raiz] = $this->cadena();
        $raiz->update(['is_visible' => false]);

        $this->get(route('dashboard'))->assertOk()->assertDontSee('Estación Cancún');
    }

    public function test_deleting_a_section_removes_its_subsections_too(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija, $nieta] = $this->cadena();

        $this->delete(route('secciones.destroy', $raiz))->assertRedirect(route('secciones.administrar'));

        $this->assertSoftDeleted($raiz);
        $this->assertSoftDeleted($hija);
        $this->assertSoftDeleted($nieta);
    }

    public function test_moving_a_section_into_another_carries_the_group_and_subtree(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija, $nieta] = $this->cadena();
        $otroGrupo = $this->grupo('Otros');
        $destino = Seccion::factory()->create(['menu_grupo_id' => $otroGrupo->id, 'nombre' => 'Destino']);

        $this->put(route('secciones.update', $hija), ['ubicacion' => 's-'.$destino->id])->assertRedirect();

        $this->assertSame($destino->id, $hija->fresh()->parent_id);
        $this->assertSame($otroGrupo->id, $hija->fresh()->menu_grupo_id);
        $this->assertSame($otroGrupo->id, $nieta->fresh()->menu_grupo_id);
    }

    public function test_section_can_move_to_the_group_root(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija] = $this->cadena();

        $this->put(route('secciones.update', $hija), ['ubicacion' => 'g-'.$raiz->menu_grupo_id])->assertRedirect();

        $this->assertNull($hija->fresh()->parent_id);
    }

    public function test_a_section_cannot_be_moved_inside_itself_or_its_descendants(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija, $nieta] = $this->cadena();

        $this->put(route('secciones.update', $raiz), ['ubicacion' => 's-'.$nieta->id])->assertInvalid(['parent_id']);
        $this->put(route('secciones.update', $raiz), ['ubicacion' => 's-'.$raiz->id])->assertInvalid(['parent_id']);
        $this->assertNull($raiz->fresh()->parent_id);
    }

    public function test_reordering_only_affects_siblings(): void
    {
        $this->comoAdministrador();
        [$raiz, $hija] = $this->cadena();
        $hermana = Seccion::factory()->create(['menu_grupo_id' => $raiz->menu_grupo_id, 'parent_id' => $raiz->id, 'orden' => 2, 'nombre' => 'Estación Tulum']);
        $hija->update(['orden' => 1]);
        $raiz->update(['orden' => 1]);

        $this->post(route('secciones.mover', $hija), ['direccion' => 'abajo'])->assertRedirect();

        $this->assertSame(2, $hija->fresh()->orden);
        $this->assertSame(1, $hermana->fresh()->orden);
        $this->assertSame(1, $raiz->fresh()->orden);
    }

    public function test_admin_page_lists_the_tree(): void
    {
        $this->comoAdministrador();
        $this->cadena();

        $this->get(route('secciones.administrar'))->assertOk()
            ->assertSee('Estación Cancún')
            ->assertSee('Juan Pérez')
            ->assertSee('Uniformes › Estación Cancún', false);
    }

    public function test_estacion_role_cannot_create_subsections(): void
    {
        [$raiz] = $this->cadena();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));

        $this->post(route('secciones.store'), ['parent_id' => $raiz->id, 'nombre' => 'X'])->assertForbidden();
    }
}
