<?php

namespace Tests\Feature\Secciones;

use App\Models\Estacion;
use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Aprobación de secciones para estaciones (Fase 3): qué secciones puede abrir
 * cada cuenta Estación, cómo se hereda a las subsecciones, el menú, el inicio
 * de la estación y quién puede aprobar.
 */
class EstacionAccesoTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    private Estacion $bacalar;

    private Estacion $chetumal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->bacalar = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true, 'orden' => 1]);
        $this->chetumal = Estacion::factory()->create(['nombre' => 'Chetumal', 'is_operativa' => true, 'orden' => 2]);
    }

    private function cuentaDe(Estacion $estacion): User
    {
        $usuario = User::factory()->create(['estacion_id' => $estacion->id]);
        $usuario->assignRole('Estación');

        return $usuario;
    }

    private function aprobar(Seccion $seccion, Estacion ...$estaciones): void
    {
        $seccion->estacionesAprobadas()->sync(collect($estaciones)->pluck('id')->all());
    }

    public function test_unapproved_section_is_not_found_for_a_station(): void
    {
        $seccion = Seccion::factory()->create();
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $seccion))->assertNotFound();
    }

    public function test_approved_section_opens_only_for_the_approved_station(): void
    {
        $seccion = Seccion::factory()->create();
        $this->aprobar($seccion, $this->bacalar);

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->get(route('secciones.show', $seccion))->assertOk();

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->chetumal));
        $this->get(route('secciones.show', $seccion))->assertNotFound();
    }

    public function test_approval_is_inherited_by_descendants(): void
    {
        $padre = Seccion::factory()->create();
        $hija = Seccion::factory()->create(['menu_grupo_id' => $padre->menu_grupo_id, 'parent_id' => $padre->id]);
        $nieta = Seccion::factory()->create(['menu_grupo_id' => $padre->menu_grupo_id, 'parent_id' => $hija->id]);
        $this->aprobar($padre, $this->bacalar);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $hija))->assertOk();
        $this->get(route('secciones.show', $nieta))->assertOk();
    }

    public function test_approving_a_child_does_not_open_its_parent(): void
    {
        $padre = Seccion::factory()->create();
        $hija = Seccion::factory()->create(['menu_grupo_id' => $padre->menu_grupo_id, 'parent_id' => $padre->id]);
        $this->aprobar($hija, $this->bacalar);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $hija))->assertOk();
        $this->get(route('secciones.show', $padre))->assertNotFound();
    }

    public function test_shared_with_all_opens_for_every_station(): void
    {
        $seccion = Seccion::factory()->create();
        $seccion->forceFill(['compartida_todas' => true])->save();

        foreach ([$this->bacalar, $this->chetumal] as $estacion) {
            $this->actingAsTwoFactorVerified($this->cuentaDe($estacion));
            $this->get(route('secciones.show', $seccion))->assertOk();
        }
    }

    public function test_hidden_sections_and_their_subtree_stay_closed_even_if_approved(): void
    {
        $padre = Seccion::factory()->create(['is_visible' => false]);
        $hija = Seccion::factory()->create(['menu_grupo_id' => $padre->menu_grupo_id, 'parent_id' => $padre->id]);
        $this->aprobar($padre, $this->bacalar);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('secciones.show', $padre))->assertNotFound();
        $this->get(route('secciones.show', $hija))->assertNotFound();
    }

    public function test_menu_lists_only_approved_sections_and_an_orphan_child_appears_as_root(): void
    {
        $grupo = MenuGrupo::query()->create(['nombre' => 'Grupo prueba', 'slug' => 'grupo-prueba', 'orden' => 1]);
        $padre = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'nombre' => 'Padre Secreto XYZ']);
        $hija = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'parent_id' => $padre->id, 'nombre' => 'Hija Aprobada ABC']);
        Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'nombre' => 'Otra Cerrada QRS']);
        $this->aprobar($hija, $this->bacalar);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Hija Aprobada ABC')
            ->assertDontSee('Padre Secreto XYZ')
            ->assertDontSee('Otra Cerrada QRS');
    }

    public function test_station_home_lists_its_approved_sections_and_no_zone_data(): void
    {
        $seccion = Seccion::factory()->create(['nombre' => 'Sección Visible Uno']);
        Seccion::factory()->create(['nombre' => 'Sección Cerrada Dos']);
        $this->aprobar($seccion, $this->bacalar);
        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sección Visible Uno')
            ->assertDontSee('Sección Cerrada Dos')
            ->assertDontSee('Indicadores clave');
    }

    public function test_administrator_approves_sections_and_the_change_is_logged(): void
    {
        $seccion = Seccion::factory()->create();
        $this->comoAdministrador();
        Activity::query()->delete();

        $this->put(route('secciones.estaciones.update', $seccion), ['estaciones' => [$this->bacalar->id]])->assertRedirect();

        $this->assertSame([$this->bacalar->id], $seccion->estacionesAprobadas()->pluck('estaciones.id')->all());
        $this->assertFalse($seccion->fresh()->compartida_todas);

        $evento = Activity::query()->where('event', 'seccion_compartida_estaciones')->sole();
        $this->assertSame([], $evento->properties['old']['estaciones']);
        $this->assertSame(['Bacalar'], $evento->properties['attributes']['estaciones']);
    }

    public function test_sharing_with_all_clears_the_specific_list(): void
    {
        $seccion = Seccion::factory()->create();
        $this->aprobar($seccion, $this->bacalar);
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => '1', 'estaciones' => [$this->chetumal->id]])->assertRedirect();

        $this->assertTrue($seccion->fresh()->compartida_todas);
        $this->assertSame(0, $seccion->estacionesAprobadas()->count());
    }

    public function test_only_jefatura_can_approve_sections(): void
    {
        $seccion = Seccion::factory()->create();

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => '1'])->assertForbidden();

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => '1'])->assertForbidden();

        $this->assertFalse($seccion->fresh()->compartida_todas);

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Jefe de Zona'));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => '1'])->assertRedirect();
        $this->assertTrue($seccion->fresh()->compartida_todas);
    }

    public function test_non_operational_station_cannot_be_approved(): void
    {
        $seccion = Seccion::factory()->create();
        $edificioZonal = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'is_operativa' => false]);
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), ['estaciones' => [$edificioZonal->id]])->assertSessionHasErrors('estaciones.0');
    }

    public function test_edificio_cannot_set_the_shared_flag_through_the_section_update_form(): void
    {
        $seccion = Seccion::factory()->create();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->put(route('secciones.update', $seccion), [
            'nombre' => $seccion->nombre,
            'ubicacion' => 'g-'.$seccion->menu_grupo_id,
            'compartida_todas' => '1',
        ]);

        $this->assertFalse($seccion->fresh()->compartida_todas);
    }
}
