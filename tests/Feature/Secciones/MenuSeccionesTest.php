<?php

namespace Tests\Feature\Secciones;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithSecciones;
use Tests\TestCase;

/**
 * Menú administrable: grupos y secciones sembrados desde el PPT, alta,
 * cambio de nombre, orden, visibilidad y baja.
 */
class MenuSeccionesTest extends TestCase
{
    use InteractsWithSecciones, RefreshDatabase;

    public function test_seeder_creates_every_group_and_section_once(): void
    {
        $this->seed(MenuSeeder::class);
        $this->seed(MenuSeeder::class);

        $esperadas = collect(config('navegacion.grupos'))->sum(fn (array $grupo) => count($grupo['secciones']));

        $this->assertSame(6, MenuGrupo::query()->count());
        $this->assertSame($esperadas, Seccion::query()->count());
        $this->assertDatabaseHas('secciones', ['slug' => 'escaleras-electricas']);
    }

    public function test_seeder_does_not_resurrect_a_deleted_section(): void
    {
        $this->seed(MenuSeeder::class);
        Seccion::query()->where('slug', 'expedientes')->first()->delete();

        $this->seed(MenuSeeder::class);

        $this->assertSame(0, Seccion::query()->where('slug', 'expedientes')->count());
    }

    public function test_navigation_lists_visible_sections_and_hides_hidden_ones(): void
    {
        $this->seed(MenuSeeder::class);
        Seccion::query()->where('slug', 'cursos')->update(['is_visible' => false]);
        $this->comoAdministrador();

        $respuesta = $this->get(route('dashboard'));

        $respuesta->assertOk();
        $respuesta->assertSee('Escaleras eléctricas');
        $respuesta->assertSee('Personal');
        $respuesta->assertDontSee('>Cursos<', false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('secciones.administrar'))->assertRedirect(route('login'));
    }

    public function test_estacion_role_cannot_manage_the_menu(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Estación'));
        $seccion = Seccion::factory()->create();

        $this->get(route('secciones.administrar'))->assertForbidden();
        $this->get(route('secciones.show', $seccion))->assertForbidden();
        $this->post(route('secciones.store'), ['menu_grupo_id' => $seccion->menu_grupo_id, 'nombre' => 'X'])->assertForbidden();
    }

    public function test_user_without_role_cannot_see_sections(): void
    {
        $this->actingAsTwoFactorVerified($this->usuarioConRol(null));

        $this->get(route('secciones.show', Seccion::factory()->create()))->assertForbidden();
    }

    public function test_zone_chief_can_open_the_admin_page(): void
    {
        $this->seed(MenuSeeder::class);
        $this->actingAsTwoFactorVerified($this->usuarioConRol('Jefe de Zona'));

        $this->get(route('secciones.administrar'))->assertOk()->assertSee('Recursos materiales');
    }

    public function test_admin_creates_a_section_and_it_is_logged(): void
    {
        $usuario = $this->comoAdministrador();
        $grupo = MenuGrupo::query()->create(['nombre' => 'RR.HH.', 'slug' => 'rrhh', 'orden' => 1]);

        $respuesta = $this->post(route('secciones.store'), [
            'menu_grupo_id' => $grupo->id,
            'nombre' => 'Control de uniformes',
            'descripcion' => 'Entrega de uniformes por estación',
            'slug' => 'intento-de-slug',
            'is_visible' => false,
        ]);

        $seccion = Seccion::query()->where('nombre', 'Control de uniformes')->firstOrFail();
        $respuesta->assertRedirect(route('secciones.show', $seccion));
        $this->assertSame('control-de-uniformes', $seccion->slug);
        $this->assertTrue($seccion->is_visible);
        $this->assertTrue(Activity::query()->where('subject_type', Seccion::class)->where('subject_id', $seccion->id)
            ->where('event', 'created')->where('causer_id', $usuario->id)->exists());
    }

    public function test_section_name_is_required_and_bounded(): void
    {
        $this->comoAdministrador();
        $grupo = MenuGrupo::query()->create(['nombre' => 'Otros', 'slug' => 'otros', 'orden' => 1]);

        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => ''])->assertInvalid(['nombre']);
        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => str_repeat('a', 161)])->assertInvalid(['nombre']);
        $this->post(route('secciones.store'), ['menu_grupo_id' => 999, 'nombre' => 'Válido'])->assertInvalid(['menu_grupo_id']);
    }

    public function test_duplicate_names_get_unique_slugs(): void
    {
        $this->comoAdministrador();
        $grupo = MenuGrupo::query()->create(['nombre' => 'Otros', 'slug' => 'otros', 'orden' => 1]);

        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => 'Cursos']);
        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => 'Cursos']);
        $this->post(route('secciones.store'), ['menu_grupo_id' => $grupo->id, 'nombre' => 'Administrar']);

        $this->assertEqualsCanonicalizing(['cursos', 'cursos-2', 'administrar-2'], Seccion::query()->pluck('slug')->all());
    }

    public function test_admin_renames_moves_and_hides_a_section_keeping_its_url(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create(['nombre' => 'Cursos', 'slug' => 'cursos']);
        $otroGrupo = MenuGrupo::query()->create(['nombre' => 'Otros', 'slug' => 'otros', 'orden' => 9]);

        $this->put(route('secciones.update', $seccion), [
            'nombre' => 'Cursos y capacitación',
            'menu_grupo_id' => $otroGrupo->id,
            'is_visible' => '0',
        ])->assertRedirect();

        $seccion->refresh();
        $this->assertSame('Cursos y capacitación', $seccion->nombre);
        $this->assertSame('cursos', $seccion->slug);
        $this->assertSame($otroGrupo->id, $seccion->menu_grupo_id);
        $this->assertFalse($seccion->is_visible);
    }

    public function test_sections_can_be_reordered(): void
    {
        $this->comoAdministrador();
        $primera = Seccion::factory()->create(['orden' => 1]);
        $segunda = Seccion::factory()->create(['menu_grupo_id' => $primera->menu_grupo_id, 'orden' => 2]);

        $this->post(route('secciones.mover', $segunda), ['direccion' => 'arriba'])->assertRedirect();

        $this->assertSame(1, $segunda->fresh()->orden);
        $this->assertSame(2, $primera->fresh()->orden);
        $this->post(route('secciones.mover', $segunda), ['direccion' => 'lado'])->assertInvalid(['direccion']);
    }

    public function test_deleting_a_section_is_a_soft_delete(): void
    {
        $this->comoAdministrador();
        $seccion = Seccion::factory()->create();
        $this->filaEn($seccion, ['dato' => 'importante']);

        $this->delete(route('secciones.destroy', $seccion))->assertRedirect(route('secciones.administrar'));

        $this->assertSoftDeleted($seccion);
        $this->assertDatabaseCount('seccion_filas', 1);
        $this->get(route('secciones.show', $seccion))->assertNotFound();
    }

    public function test_groups_can_be_created_renamed_and_deleted_only_when_empty(): void
    {
        $this->comoAdministrador();

        $this->post(route('secciones.grupos.store'), ['nombre' => 'Jurídico'])->assertRedirect();
        $grupo = MenuGrupo::query()->where('nombre', 'Jurídico')->firstOrFail();

        $this->put(route('secciones.grupos.update', $grupo), ['nombre' => 'Jurídico y normatividad']);
        $this->assertSame('Jurídico y normatividad', $grupo->fresh()->nombre);

        $seccion = Seccion::factory()->create(['menu_grupo_id' => $grupo->id]);
        $this->delete(route('secciones.grupos.destroy', $grupo))->assertSessionHasErrors('grupo');

        $seccion->forceDelete();
        $this->delete(route('secciones.grupos.destroy', $grupo))->assertRedirect();
        $this->assertModelMissing($grupo);
    }
}
