<?php

namespace Tests\Feature\Secciones;

use App\Enums\TipoColumna;
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
        $seccion->estacionesAprobadas()->sync(collect($estaciones)->mapWithKeys(fn (Estacion $e) => [$e->id => ['nivel' => 'ver']])->all());
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
        $seccion->forceFill(['acceso_todas' => 'ver'])->save();

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

    public function test_administrator_sets_levels_per_station_and_the_change_is_logged(): void
    {
        $seccion = Seccion::factory()->create();
        $this->comoAdministrador();
        Activity::query()->delete();

        $this->put(route('secciones.estaciones.update', $seccion), [
            'todas' => '',
            'estaciones' => [$this->bacalar->id => 'editar', $this->chetumal->id => 'ver'],
        ])->assertRedirect();

        $this->assertSame(
            [$this->bacalar->id => 'editar', $this->chetumal->id => 'ver'],
            $seccion->estacionesAprobadas()->orderBy('estaciones.orden')->get()->mapWithKeys(fn ($e) => [$e->id => $e->pivot->nivel])->all(),
        );
        $this->assertNull($seccion->fresh()->acceso_todas);

        $evento = Activity::query()->where('event', 'seccion_compartida_estaciones')->sole();
        $this->assertSame([], $evento->properties['old']['estaciones']);
        $this->assertSame(['Bacalar' => 'editar', 'Chetumal' => 'ver'], $evento->properties['attributes']['estaciones']);
    }

    public function test_an_empty_level_removes_the_station(): void
    {
        $seccion = Seccion::factory()->create();
        $this->aprobar($seccion, $this->bacalar, $this->chetumal);
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), [
            'todas' => '',
            'estaciones' => [$this->bacalar->id => '', $this->chetumal->id => 'ver'],
        ])->assertRedirect();

        $this->assertSame([$this->chetumal->id], $seccion->estacionesAprobadas()->pluck('estaciones.id')->all());
    }

    public function test_all_stations_level_coexists_with_a_higher_level_for_one_station(): void
    {
        $seccion = Seccion::factory()->create();
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), [
            'todas' => 'ver',
            'estaciones' => [$this->chetumal->id => 'editar'],
        ])->assertRedirect();

        $this->assertSame('ver', $seccion->fresh()->acceso_todas->value);
        $this->assertSame(1, $seccion->estacionesAprobadas()->count());
    }

    public function test_an_incomplete_form_does_not_revoke_permissions(): void
    {
        $seccion = Seccion::factory()->create();
        $tabla = $this->tablaConColumnas($seccion, 'Hoja', ['Nombre' => TipoColumna::Texto]);
        $this->aprobar($seccion, $this->bacalar);
        $tabla->estacionesAprobadas()->sync([$this->bacalar->id => ['nivel' => 'editar']]);
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), [])->assertSessionHasErrors(['todas', 'estaciones']);
        $this->put(route('secciones.tablas.acceso.update', [$seccion, $tabla]), [])->assertSessionHasErrors('estaciones');

        $this->assertSame(1, $seccion->estacionesAprobadas()->count());
        $this->assertSame(1, $tabla->estacionesAprobadas()->count());
    }

    public function test_changing_the_all_stations_level_is_logged(): void
    {
        $seccion = Seccion::factory()->create();
        $this->comoAdministrador();
        Activity::query()->delete();

        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => 'ver', 'estaciones' => []])->assertSessionHasNoErrors();

        $evento = Activity::query()->where('event', 'seccion_compartida_estaciones')->sole();
        $this->assertNull($evento->properties['old']['todas']);
        $this->assertSame('ver', $evento->properties['attributes']['todas']);
    }

    public function test_a_legacy_approval_without_level_gives_no_access_until_reviewed(): void
    {
        $seccion = Seccion::factory()->create();
        // Aprobación del modelo anterior (solo veía sus filas): la migración la deja sin nivel.
        $seccion->estacionesAprobadas()->sync([$this->bacalar->id => ['nivel' => null]]);

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->get(route('secciones.show', $seccion))->assertNotFound();

        $this->comoAdministrador();
        $this->get(route('secciones.show', $seccion))->assertOk()->assertSee('Pendiente de revisar');
    }

    public function test_invalid_levels_and_unknown_stations_are_rejected(): void
    {
        $seccion = Seccion::factory()->create();
        $this->comoAdministrador();

        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => 'admin'])->assertSessionHasErrors('todas');
        $this->put(route('secciones.estaciones.update', $seccion), ['estaciones' => [$this->bacalar->id => 'borrar']])->assertSessionHasErrors('estaciones.'.$this->bacalar->id);
        $this->put(route('secciones.estaciones.update', $seccion), ['estaciones' => [999999 => 'ver']])->assertSessionHasErrors('estaciones');
        $this->put(route('secciones.estaciones.update', $seccion), ['estaciones' => ['x' => 'ver']])->assertSessionHasErrors('estaciones');

        $this->assertSame(0, $seccion->estacionesAprobadas()->count());
    }

    public function test_only_jefatura_can_set_station_permissions(): void
    {
        $seccion = Seccion::factory()->create();
        $tabla = $this->tablaConColumnas($seccion, 'Hoja', ['Nombre' => TipoColumna::Texto]);

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => 'editar'])->assertForbidden();
        $this->put(route('secciones.tablas.acceso.update', [$seccion, $tabla]), ['estaciones' => [$this->bacalar->id => 'editar']])->assertForbidden();

        $this->actingAsTwoFactorVerified($this->cuentaDe($this->bacalar));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => 'editar'])->assertForbidden();
        $this->put(route('secciones.tablas.acceso.update', [$seccion, $tabla]), ['estaciones' => [$this->bacalar->id => 'editar']])->assertForbidden();

        $this->assertNull($seccion->fresh()->acceso_todas);
        $this->assertSame(0, $tabla->estacionesAprobadas()->count());

        $this->actingAsTwoFactorVerified($this->usuarioConRol('Jefe de Zona'));
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => 'editar', 'estaciones' => []])->assertSessionHasNoErrors();
        $this->assertSame('editar', $seccion->fresh()->acceso_todas->value);
    }

    public function test_the_edificio_zonal_can_receive_permissions_like_any_station(): void
    {
        $seccion = Seccion::factory()->create();
        $edificioZonal = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'is_operativa' => false, 'orden' => 10]);
        $this->comoAdministrador();

        $this->get(route('secciones.show', $seccion))->assertOk()->assertSee('Edificio Zonal Este');
        $this->put(route('secciones.estaciones.update', $seccion), ['todas' => '', 'estaciones' => [$edificioZonal->id => 'editar']])->assertSessionHasNoErrors();

        $this->actingAsTwoFactorVerified($this->cuentaDe($edificioZonal));
        $this->get(route('secciones.show', $seccion))->assertOk();
    }

    public function test_administrator_sets_sheet_permissions_and_the_change_is_logged(): void
    {
        $seccion = Seccion::factory()->create();
        $tabla = $this->tablaConColumnas($seccion, 'Mantenimiento', ['Nombre' => TipoColumna::Texto]);
        $this->comoAdministrador();
        Activity::query()->delete();

        $this->put(route('secciones.tablas.acceso.update', [$seccion, $tabla]), [
            'estaciones' => [$this->bacalar->id => 'editar', $this->chetumal->id => 'ver'],
        ])->assertRedirect();

        $this->assertSame(2, $tabla->estacionesAprobadas()->count());
        $this->assertSame(0, $seccion->estacionesAprobadas()->count(), 'el permiso de hoja no toca el de la sección');

        $evento = Activity::query()->where('event', 'hoja_compartida_estaciones')->sole();
        $this->assertSame($tabla->id, $evento->properties['tabla_id']);
        $this->assertSame(['Bacalar' => 'editar', 'Chetumal' => 'ver'], $evento->properties['attributes']);
    }

    public function test_a_sheet_of_another_section_cannot_be_targeted(): void
    {
        $seccion = Seccion::factory()->create();
        $otra = Seccion::factory()->create();
        $tablaAjena = $this->tablaConColumnas($otra, 'Ajena', ['Nombre' => TipoColumna::Texto]);
        $this->comoAdministrador();

        $this->put(route('secciones.tablas.acceso.update', [$seccion, $tablaAjena]), ['estaciones' => [$this->bacalar->id => 'ver']])->assertNotFound();
        $this->assertSame(0, $tablaAjena->estacionesAprobadas()->count());
    }

    public function test_edificio_cannot_set_the_shared_level_through_the_section_update_form(): void
    {
        $seccion = Seccion::factory()->create();
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Edificio'));

        $this->put(route('secciones.update', $seccion), [
            'nombre' => $seccion->nombre,
            'ubicacion' => 'g-'.$seccion->menu_grupo_id,
            'acceso_todas' => 'editar',
        ]);

        $this->assertNull($seccion->fresh()->acceso_todas);
    }
}
