<?php

namespace Tests\Feature;

use App\Models\Equipo;
use App\Models\Estacion;
use App\Models\MenuGrupo;
use App\Models\User;
use App\Support\Areas;
use App\Support\Roles;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\MenuSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Equipos por estación: administración (solo Administrador), grupos del menú, estaciones y vínculo con Tickets. */
class EquiposTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(MenuSeeder::class);
        $this->seed(EquipoSeeder::class);
        config([
            'ecosistema.puente.url' => 'https://tickets.ejemplo.test',
            'ecosistema.puente.secreto_salida' => 'secreto-dashboard-a-tickets-de-prueba-0123456789',
        ]);
    }

    private function cuenta(string $rol): User
    {
        $user = User::factory()->create();
        $user->assignRole($rol);

        return $user;
    }

    public function test_solo_el_administrador_ve_y_edita_equipos(): void
    {
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::JEFE_ZONA));
        $this->get(route('equipos.index'))->assertForbidden();
        $this->post(route('equipos.store'), ['nombre' => 'Calidad'])->assertForbidden();

        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));
        $this->get(route('equipos.index'))->assertOk()->assertSee('Recursos Humanos');
    }

    public function test_crear_un_equipo_lo_vincula_con_tickets_solo_en_sus_estaciones(): void
    {
        Http::fake(['*' => Http::response(['equipo_central_id' => 9, 'creados' => 2], 200)]);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25']);
        $limones = Estacion::factory()->create(['nombre' => 'Limones', 'codigo' => '28']);
        Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29']);
        $grupo = MenuGrupo::query()->where('slug', 'otros')->firstOrFail();
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));

        $this->post(route('equipos.store'), ['nombre' => 'Calidad', 'estaciones' => [$tulum->id, $limones->id], 'grupos' => [$grupo->id]])
            ->assertRedirect(route('equipos.index'))->assertSessionHas('status');

        $equipo = Equipo::query()->where('clave', 'calidad')->firstOrFail();
        $this->assertEqualsCanonicalizing([$tulum->id, $limones->id], $equipo->estaciones()->pluck('estaciones.id')->all());
        $this->assertSame('calidad', Areas::deGrupo('otros'));
        $this->assertArrayHasKey('calidad', Areas::nombres());

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/interno/equipos')
            && $request['clave'] === 'calidad'
            && collect($request['estaciones'])->pluck('codigo')->sort()->values()->all() === ['25', '28']);
    }

    public function test_si_tickets_no_responde_el_equipo_se_guarda_y_se_avisa(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));

        $this->post(route('equipos.store'), ['nombre' => 'Calidad'])->assertSessionHas('warning');

        $this->assertDatabaseHas('equipos', ['clave' => 'calidad']);
    }

    public function test_un_grupo_del_menu_cambia_de_equipo_y_la_clave_no_cambia(): void
    {
        Http::fake(['*' => Http::response(['creados' => 0], 200)]);
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));
        $rm = Equipo::query()->where('clave', 'rm')->firstOrFail();
        $rrhh = MenuGrupo::query()->where('slug', 'rrhh')->firstOrFail();
        $this->assertSame('rh', Areas::deGrupo('rrhh'));

        $this->put(route('equipos.update', $rm), ['nombre' => 'Materiales', 'grupos' => [$rrhh->id]])->assertRedirect();

        $this->assertSame('rm', Areas::deGrupo('rrhh'));
        $this->assertNull(Areas::deGrupo('recursos-materiales'));
        $this->assertSame('rm', $rm->fresh()->clave);
        $this->put(route('equipos.update', $rm), ['nombre' => 'X', 'clave' => 'otra'])->assertSessionHasErrors('clave');
    }

    public function test_no_se_elimina_un_equipo_con_cuentas(): void
    {
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));
        $rh = Equipo::query()->where('clave', 'rh')->firstOrFail();
        $cuenta = User::factory()->create(['area' => 'rh', 'estacion_id' => Estacion::factory()->create()->id]);
        $cuenta->assignRole(Roles::ESTACION);

        $this->delete(route('equipos.destroy', $rh))->assertSessionHasErrors('equipo');
        $this->assertDatabaseHas('equipos', ['clave' => 'rh']);

        $cuenta->delete();
        $this->delete(route('equipos.destroy', $rh))->assertRedirect(route('equipos.index'));
        $this->assertDatabaseMissing('equipos', ['clave' => 'rh']);
    }

    public function test_una_cuenta_puede_tener_equipos_adicionales_y_usa_los_modulos_de_todos(): void
    {
        config(['equipos.estrictas' => true]);
        $estacion = Estacion::factory()->create();
        Equipo::query()->whereIn('clave', ['rh', 'rm'])->get()->each(fn (Equipo $e) => $e->estaciones()->attach($estacion->id));
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));

        $this->post(route('usuarios.store'), [
            'name' => 'Persona Dos', 'email' => 'dos@ejemplo.test', 'password' => 'Contrasena-Larga-123!', 'password_confirmation' => 'Contrasena-Larga-123!',
            'roles' => [Roles::ESTACION], 'estacion_id' => $estacion->id, 'area' => 'rh', 'areas_adicionales' => ['rm', 'rh'],
        ])->assertSessionHasNoErrors();

        $usuario = User::query()->where('email', 'dos@ejemplo.test')->firstOrFail();
        $this->assertSame(['rh', 'rm'], $usuario->areas());
        $this->assertSame(['rm'], $usuario->areas_adicionales);
        $this->assertTrue($usuario->puedeUsarArea('rh'));
        $this->assertTrue($usuario->puedeUsarArea('rm'));
        $this->assertFalse($usuario->puedeUsarArea('seguridad'));

        $this->get(route('usuarios.index', ['area' => 'rm']))->assertSee('Persona Dos');
        $this->post(route('usuarios.store'), [
            'name' => 'Tres', 'email' => 'tres@ejemplo.test', 'password' => 'Contrasena-Larga-123!', 'password_confirmation' => 'Contrasena-Larga-123!',
            'roles' => [Roles::ESTACION], 'estacion_id' => $estacion->id, 'area' => 'rh', 'areas_adicionales' => ['no-existe'],
        ])->assertSessionHasErrors('areas_adicionales.0');
    }

    public function test_la_cuenta_estacion_solo_puede_tener_equipos_que_existen_en_su_estacion(): void
    {
        config(['equipos.estrictas' => true]);
        $con = Estacion::factory()->create();
        $sin = Estacion::factory()->create();
        Equipo::query()->where('clave', 'rh')->firstOrFail()->estaciones()->sync([$con->id]);
        $this->actingAsTwoFactorVerified($this->cuenta(Roles::ADMINISTRADOR));
        $datos = fn (Estacion $e, string $area) => [
            'name' => 'Persona Uno', 'email' => 'p'.$e->id.'@ejemplo.test', 'password' => 'Contrasena-Larga-123!', 'password_confirmation' => 'Contrasena-Larga-123!',
            'roles' => [Roles::ESTACION], 'estacion_id' => $e->id, 'area' => $area,
        ];

        $this->post(route('usuarios.store'), $datos($sin, 'rh'))->assertSessionHasErrors('area');
        $this->post(route('usuarios.store'), $datos($con, 'rh'))->assertSessionHasNoErrors();
        $this->post(route('usuarios.store'), $datos($sin, Areas::JEFE))->assertSessionHasNoErrors();
    }
}
