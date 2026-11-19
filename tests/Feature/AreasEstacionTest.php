<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\HorarioPeriodo;
use App\Models\RegistroDiario;
use App\Models\SolicitudInsumo;
use App\Models\User;
use App\Notifications\AvisoDashboard;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Services\InicioEstacionService;
use App\Support\Areas;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Cada cuenta de estación solo usa los módulos de su área (con AREAS_ESTRICTAS=true). */
class AreasEstacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
    }

    private function cuenta(string $rol, ?string $area = null): User
    {
        $user = User::factory()->create([
            'estacion_id' => $rol === Roles::ESTACION ? Estacion::factory()->create()->id : null,
            'area' => $area,
        ]);
        $user->assignRole($rol);

        return $user;
    }

    public function test_rm_usa_insumos_pero_no_asistencia_ni_horarios(): void
    {
        config(['equipos.estrictas' => true]);
        $rm = $this->cuenta(Roles::ESTACION, Areas::RM);

        $this->assertTrue(Gate::forUser($rm)->allows('viewAny', SolicitudInsumo::class));
        $this->assertFalse(Gate::forUser($rm)->allows('viewAny', HorarioPeriodo::class));
        $this->assertFalse(Gate::forUser($rm)->allows('viewAny', RegistroDiario::class));
    }

    public function test_rh_usa_horarios_pero_no_insumos(): void
    {
        config(['equipos.estrictas' => true]);
        $rh = $this->cuenta(Roles::ESTACION, Areas::RH);

        $this->assertTrue(Gate::forUser($rh)->allows('viewAny', HorarioPeriodo::class));
        $this->assertFalse(Gate::forUser($rh)->allows('viewAny', SolicitudInsumo::class));
    }

    public function test_estacion_sin_area_no_usa_ningun_modulo_de_area(): void
    {
        config(['equipos.estrictas' => true]);
        $sin = $this->cuenta(Roles::ESTACION);

        $this->assertFalse(Gate::forUser($sin)->allows('viewAny', SolicitudInsumo::class));
        $this->assertFalse(Gate::forUser($sin)->allows('viewAny', HorarioPeriodo::class));
    }

    public function test_zona_no_se_restringe_por_area(): void
    {
        config(['equipos.estrictas' => true]);
        $jefe = $this->cuenta(Roles::JEFE_ZONA);

        $this->assertTrue(Gate::forUser($jefe)->allows('viewAny', SolicitudInsumo::class));
    }

    public function test_sin_modo_estricto_la_estacion_conserva_su_acceso(): void
    {
        config(['equipos.estrictas' => false]);
        $sin = $this->cuenta(Roles::ESTACION);

        $this->assertTrue(Gate::forUser($sin)->allows('viewAny', SolicitudInsumo::class));
    }

    public function test_el_menu_oculta_los_grupos_de_otra_area(): void
    {
        config(['equipos.estrictas' => true]);
        $rm = $this->cuenta(Roles::ESTACION, Areas::RM);

        $this->assertTrue($rm->puedeUsarArea(Areas::GRUPOS['recursos-materiales']));
        $this->assertFalse($rm->puedeUsarArea(Areas::GRUPOS['rrhh']));
        $this->assertTrue($rm->puedeUsarArea(Areas::GRUPOS['otros'] ?? null));
    }

    public function test_los_avisos_de_un_modulo_solo_llegan_a_las_cuentas_de_su_area(): void
    {
        config(['equipos.estrictas' => true]);
        $estacion = Estacion::factory()->create();
        $crear = function (string $area) use ($estacion): User {
            $u = User::factory()->create(['estacion_id' => $estacion->id, 'area' => $area]);
            $u->assignRole(Roles::ESTACION);

            return $u;
        };
        $rh = $crear(Areas::RH);
        $rm = $crear(Areas::RM);

        Notification::fake();
        app(AvisosEcosistema::class)->asistenciaPendiente($estacion);

        Notification::assertSentTo($rh, AvisoDashboard::class);
        Notification::assertNotSentTo($rm, AvisoDashboard::class);
    }

    public function test_el_inicio_muestra_solo_el_area_y_los_atajos_de_la_cuenta(): void
    {
        config(['equipos.estrictas' => true]);
        $rm = $this->cuenta(Roles::ESTACION, Areas::RM);

        $inicio = app(InicioEstacionService::class)->para($rm);

        $this->assertSame('Recursos Materiales', $inicio['area']);
        $this->assertNull($inicio['asistencia']);
        $this->assertContains(route('insumos.index'), array_column($inicio['accesos'], 'ruta'));
        $this->assertNotContains(route('horarios.index'), array_column($inicio['accesos'], 'ruta'));
    }

    public function test_el_inicio_no_cambia_sin_areas_estrictas(): void
    {
        config(['equipos.estrictas' => false]);
        $sin = $this->cuenta(Roles::ESTACION);

        $inicio = app(InicioEstacionService::class)->para($sin);

        $this->assertNull($inicio['area']);
        $this->assertSame([], $inicio['accesos']);
    }
}
