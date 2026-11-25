<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\User;
use App\Support\MenuNavegacion;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Menú: módulos separados de documentos, sin duplicados y con una sola entrada de vacaciones. */
class MenuOrdenadoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const MIGRACION = 'migrations/2026_12_05_000000_ocultar_secciones_duplicadas_del_menu.php';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(MenuSeeder::class);
    }

    private function usuario(string $rol, bool $conFicha = false): User
    {
        $estacion = Estacion::factory()->create();
        $usuario = User::factory()->create(['estacion_id' => $rol === 'Estación' ? $estacion->id : null]);
        if ($conFicha) {
            $usuario->forceFill(['empleado_id' => Empleado::factory()->create(['estacion_id' => $estacion->id])->id])->save();
        }
        $usuario->assignRole($rol);
        $this->actingAsTwoFactorVerified($usuario);

        return $usuario;
    }

    /** @return list<string> */
    private function etiquetasRrhh(User $usuario): array
    {
        $grupo = app(MenuNavegacion::class)->grupos($usuario, Request::create('/'))->firstWhere('id', 'rrhh');

        return array_column($grupo['items'], 'label');
    }

    public function test_zone_has_a_single_vacation_entry(): void
    {
        $etiquetas = $this->etiquetasRrhh($this->usuario('Jefe de Zona', conFicha: true));

        $this->assertSame(['Vacaciones'], array_values(array_filter($etiquetas, fn (string $e) => str_contains(mb_strtolower($e), 'vacaciones'))));
    }

    public function test_personal_account_keeps_its_own_vacation_entry(): void
    {
        $this->assertSame(['Mis vacaciones'], $this->etiquetasRrhh($this->usuario('Personal', conFicha: true)));
    }

    public function test_new_installs_do_not_seed_sections_that_duplicate_modules(): void
    {
        $this->assertDatabaseMissing('secciones', ['slug' => 'rol-de-vacaciones']);
        $this->assertDatabaseMissing('secciones', ['slug' => 'rol-horarios']);
    }

    public function test_dropdown_separates_modules_from_documents(): void
    {
        $this->usuario('Jefe de Zona');

        $this->get(route('dashboard'))->assertOk()
            ->assertSeeInOrder(['data-menu-titulo="modulos"', 'Rol de horarios', 'data-menu-titulo="documentos"', 'Relación nominal'], false);
    }

    public function test_vacation_tabs_connect_my_vacations_and_the_roster(): void
    {
        $this->usuario('Estación', conFicha: true);

        $this->get(route('vacaciones.index'))->assertOk()->assertSee('href="'.route('vacaciones.mi.show').'" ', false);
        $this->get(route('vacaciones.mi.show'))->assertOk()->assertSee('href="'.route('vacaciones.index').'" ', false);
    }

    public function test_migration_hides_only_the_empty_duplicate_sections_and_logs_it(): void
    {
        $grupo = MenuGrupo::query()->where('slug', 'rrhh')->firstOrFail();
        $vacia = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'nombre' => 'Rol de vacaciones', 'slug' => 'rol-de-vacaciones', 'is_visible' => true]);
        $conDatos = Seccion::factory()->create(['menu_grupo_id' => $grupo->id, 'nombre' => 'Rol horarios', 'slug' => 'rol-horarios', 'is_visible' => true]);
        $conDatos->enlaces()->create(['titulo' => 'Horarios en la nube', 'url' => 'https://example.com/h']);

        (require database_path(self::MIGRACION))->up();

        $this->assertFalse($vacia->refresh()->is_visible);
        $this->assertTrue($conDatos->refresh()->is_visible);
        $this->assertTrue(Activity::query()->where('subject_type', Seccion::class)->where('subject_id', $vacia->id)->where('event', 'updated')->exists());
    }
}
