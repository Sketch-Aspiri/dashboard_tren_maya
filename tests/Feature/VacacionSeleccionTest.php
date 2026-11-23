<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Models\VacacionCatalogoPeriodo;
use App\Models\VacacionEstacionTope;
use App\Models\VacacionPlan;
use App\Services\Vacaciones\SeleccionVacacionService;
use App\Services\Vacaciones\VacacionCatalogoService;
use App\Services\Vacaciones\VacacionDiasCorrespondientes;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Selección de vacaciones 2027 por el personal: catálogo con la regla del Excel 2026, restricciones
 * (periodos autorizados, días por antigüedad, un periodo por trimestre, tope por estación) y revisión de la Zona.
 */
class VacacionSeleccionTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vacaciones.anio_seleccion' => 2027]);
        $this->seed(RoleSeeder::class);
        $this->estacion = Estacion::factory()->create(['nombre' => 'Tulum', 'codigo' => '25']);
        app(VacacionCatalogoService::class)->generar(2027);
    }

    private function persona(?Estacion $estacion = null, string $ingreso = '2020-03-01'): User
    {
        $empleado = Empleado::factory()->create(['estacion_id' => ($estacion ?? $this->estacion)->id, 'fecha_ingreso' => $ingreso]);
        $user = User::factory()->create();
        $user->forceFill(['empleado_id' => $empleado->id])->save();
        $user->assignRole('Personal');

        return $user;
    }

    private function como(User $user): static
    {
        $this->actingAsTwoFactorVerified($user);

        return $this;
    }

    private function zona(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Jefe de Zona');

        return $user;
    }

    private function periodo(string $inicio, int $dias): VacacionCatalogoPeriodo
    {
        return VacacionCatalogoPeriodo::query()->where(['anio' => 2027, 'inicio' => $inicio, 'dias' => $dias])->firstOrFail();
    }

    public function test_catalog_follows_the_2026_rule(): void
    {
        // 2026: "26 Ene. al 9 Feb." = 10 días (2 de feb inhábil) y "4 al 11 May." = 5 (5 de may inhábil).
        app(VacacionCatalogoService::class)->generar(2026);
        $a = VacacionCatalogoPeriodo::query()->where(['anio' => 2026, 'inicio' => '2026-01-26', 'dias' => 10])->first();
        $b = VacacionCatalogoPeriodo::query()->where(['anio' => 2026, 'inicio' => '2026-05-04', 'dias' => 5])->first();

        $this->assertSame('2026-02-09', $a->fin->toDateString());
        $this->assertSame('2026-05-11', $b->fin->toDateString());
        // Semana Santa 2026 (30 mar–10 abr) no autorizada; 13–17 abr sí.
        $this->assertFalse(VacacionCatalogoPeriodo::query()->where(['anio' => 2026, 'inicio' => '2026-03-30', 'dias' => 5])->first()->autorizado);
        $this->assertTrue(VacacionCatalogoPeriodo::query()->where(['anio' => 2026, 'inicio' => '2026-04-13', 'dias' => 5])->first()->autorizado);
        $this->assertFalse(VacacionCatalogoPeriodo::query()->where(['anio' => 2026, 'inicio' => '2026-12-14', 'dias' => 5])->first()->autorizado);
    }

    public function test_catalog_2027_starts_on_first_working_monday_and_blocks_holy_week(): void
    {
        $this->assertSame(1, VacacionCatalogoPeriodo::query()->where('anio', 2027)->min('trimestre'));
        $this->assertSame('2027-01-04', CarbonImmutable::parse(VacacionCatalogoPeriodo::query()->where('anio', 2027)->min('inicio'))->toDateString());
        $this->assertFalse($this->periodo('2027-03-22', 5)->autorizado);
        $this->assertFalse($this->periodo('2027-03-29', 5)->autorizado);
        $this->assertTrue($this->periodo('2027-04-05', 5)->autorizado);
        // Generar otra vez no duplica.
        $total = VacacionCatalogoPeriodo::query()->where('anio', 2027)->count();
        app(VacacionCatalogoService::class)->generar(2027);
        $this->assertSame($total, VacacionCatalogoPeriodo::query()->where('anio', 2027)->count());
    }

    public function test_days_by_seniority(): void
    {
        $this->assertSame(20, VacacionDiasCorrespondientes::para(CarbonImmutable::parse('2020-05-01'), 2027));
        $this->assertSame(20, VacacionDiasCorrespondientes::para(CarbonImmutable::parse('2027-01-16'), 2027));
        $this->assertSame(10, VacacionDiasCorrespondientes::para(CarbonImmutable::parse('2027-03-01'), 2027));
        $this->assertSame(0, VacacionDiasCorrespondientes::para(CarbonImmutable::parse('2027-07-01'), 2027));
    }

    public function test_person_picks_sends_and_zone_authorizes(): void
    {
        $persona = $this->persona();
        $a = $this->periodo('2027-01-11', 10);
        $b = $this->periodo('2027-05-10', 10);

        $this->como($persona)->get(route('vacaciones.mi.show'))->assertOk()->assertSee('20');
        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $a->id, 2 => '', 3 => '', 4 => '']])->assertSessionHasNoErrors();
        $this->como($persona)->post(route('vacaciones.mi.enviar'))->assertSessionHasErrors('plan');

        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $a->id, 2 => $b->id]])->assertSessionHasNoErrors();
        $this->como($persona)->post(route('vacaciones.mi.enviar'))->assertSessionHasNoErrors();

        $plan = VacacionPlan::query()->firstOrFail();
        $this->assertSame('enviada', $plan->estado);

        $this->como($this->zona())->post(route('vacaciones.seleccion.autorizar', $plan))->assertSessionHasNoErrors();
        $this->assertSame('autorizada', $plan->fresh()->estado);
    }

    public function test_rejects_unauthorized_period_excess_days_and_two_in_a_quarter(): void
    {
        $persona = $this->persona();
        $plan = app(SeleccionVacacionService::class)->planDe($persona->empleado, 2027);
        $santa = $this->periodo('2027-03-22', 5);

        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $santa->id]])->assertSessionHasErrors('plan');
        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $this->periodo('2027-01-11', 5)->id, 2 => $this->periodo('2027-04-12', 5)->id, 3 => $this->periodo('2027-07-12', 10)->id, 4 => $this->periodo('2027-10-11', 10)->id]])->assertSessionHasErrors('plan');
        // Periodo de un trimestre en la casilla de otro.
        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [2 => $this->periodo('2027-01-11', 5)->id]])->assertSessionHasErrors('plan');

        $this->assertSame(0, $plan->fresh()->selecciones()->count());
    }

    public function test_station_cap_blocks_the_third_person(): void
    {
        $periodo = $this->periodo('2027-02-08', 5);
        $personas = [$this->persona(), $this->persona(), $this->persona()];

        foreach ([0, 1] as $i) {
            $this->como($personas[$i])->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $periodo->id, 2 => $this->periodo('2027-05-10', 10)->id, 3 => $this->periodo('2027-07-12', 5)->id]])->assertSessionHasNoErrors();
            $this->como($personas[$i])->post(route('vacaciones.mi.enviar'))->assertSessionHasNoErrors();
        }

        // El tercero ya no puede coincidir en febrero (tope 2 por defecto) ni en el solape de 10 días.
        $this->como($personas[2])->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $periodo->id]])->assertSessionHasErrors('plan');
        $this->como($personas[2])->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $this->periodo('2027-02-02', 10)->id]])->assertSessionHasErrors('plan');

        // Con tope 3 sí cabe.
        VacacionEstacionTope::query()->create(['anio' => 2027, 'estacion_id' => $this->estacion->id, 'max_simultaneos' => 3]);
        $this->como($personas[2])->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $periodo->id]])->assertSessionHasNoErrors();
    }

    public function test_other_station_does_not_share_the_cap(): void
    {
        $otra = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29']);
        $periodo = $this->periodo('2027-02-08', 5);
        VacacionEstacionTope::query()->create(['anio' => 2027, 'estacion_id' => $this->estacion->id, 'max_simultaneos' => 1]);

        $uno = $this->persona();
        $this->como($uno)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $periodo->id, 2 => $this->periodo('2027-05-10', 10)->id, 3 => $this->periodo('2027-07-12', 5)->id]])->assertSessionHasNoErrors();
        $this->como($uno)->post(route('vacaciones.mi.enviar'))->assertSessionHasNoErrors();

        $this->como($this->persona($otra))->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $periodo->id]])->assertSessionHasNoErrors();
    }

    public function test_rejected_plan_can_be_corrected_and_withdraw_works(): void
    {
        $persona = $this->persona();
        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $this->periodo('2027-01-11', 10)->id, 2 => $this->periodo('2027-05-10', 10)->id]]);
        $this->como($persona)->post(route('vacaciones.mi.enviar'));
        $plan = VacacionPlan::query()->firstOrFail();

        $this->como($this->zona())->post(route('vacaciones.seleccion.rechazar', $plan), ['comentario' => ''])->assertSessionHasErrors('comentario');
        $this->como($this->zona())->post(route('vacaciones.seleccion.rechazar', $plan), ['comentario' => 'Cambia mayo'])->assertSessionHasNoErrors();
        $this->assertSame('rechazada', $plan->fresh()->estado);

        $this->como($persona)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $this->periodo('2027-01-11', 10)->id, 2 => $this->periodo('2027-06-14', 10)->id]])->assertSessionHasNoErrors();
        $this->assertSame('borrador', $plan->fresh()->estado);
        $this->como($persona)->post(route('vacaciones.mi.enviar'))->assertSessionHasNoErrors();
        $this->como($persona)->post(route('vacaciones.mi.retirar'))->assertSessionHasNoErrors();
        $this->assertSame('borrador', $plan->fresh()->estado);
    }

    public function test_person_without_days_cannot_send(): void
    {
        $persona = $this->persona(ingreso: '2027-08-01');

        $this->como($persona)->post(route('vacaciones.mi.enviar'))->assertSessionHasErrors('plan');
    }

    public function test_personal_role_is_locked_to_its_own_pages(): void
    {
        $persona = $this->persona();

        $this->como($persona)->get(route('dashboard'))->assertRedirect(route('vacaciones.mi.show'));
        $this->como($persona)->get(route('vacaciones.index'))->assertForbidden();
        $this->como($persona)->get(route('vacaciones.seleccion.revision'))->assertForbidden();
        $this->como($persona)->get(route('vacaciones.seleccion.catalogo'))->assertForbidden();
        $this->como($persona)->get(route('agenda.personal.index'))->assertForbidden();
        $this->como($persona)->post(route('dashboard.asistente'))->assertForbidden();
        $this->como($persona)->delete(route('profile.destroy'))->assertForbidden();
    }

    public function test_station_sees_only_its_people_and_cannot_resolve(): void
    {
        $otra = Estacion::factory()->create(['nombre' => 'Bacalar', 'codigo' => '29']);
        $propia = $this->persona();
        $ajena = $this->persona($otra);

        foreach ([$propia, $ajena] as $p) {
            $this->como($p)->put(route('vacaciones.mi.guardar'), ['periodos' => [1 => $this->periodo('2027-01-11', 10)->id, 2 => $this->periodo('2027-05-10', 10)->id]]);
            $this->como($p)->post(route('vacaciones.mi.enviar'));
        }

        $cuenta = User::factory()->create(['estacion_id' => $this->estacion->id]);
        $cuenta->assignRole('Estación');

        $this->como($cuenta)->get(route('vacaciones.seleccion.revision'))
            ->assertOk()->assertSee($propia->empleado->nombre_completo, false)->assertDontSee($ajena->empleado->nombre_completo, false);
        $this->como($cuenta)->post(route('vacaciones.seleccion.autorizar', VacacionPlan::query()->where('estacion_id', $this->estacion->id)->first()))->assertForbidden();
        $this->como($cuenta)->get(route('vacaciones.seleccion.catalogo'))->assertForbidden();
    }

    public function test_zone_manages_catalog_and_topes(): void
    {
        $zona = $this->zona();
        $periodo = $this->periodo('2027-01-11', 5);

        $this->como($zona)->get(route('vacaciones.seleccion.catalogo'))->assertOk();
        $this->como($zona)->patch(route('vacaciones.seleccion.catalogo.periodo', $periodo))->assertSessionHasNoErrors();
        $this->assertFalse($periodo->fresh()->autorizado);

        $this->como($zona)->put(route('vacaciones.seleccion.catalogo.topes'), ['topes' => [$this->estacion->id => 4]])->assertSessionHasNoErrors();
        $this->assertSame(4, (int) VacacionEstacionTope::query()->value('max_simultaneos'));
        $this->como($zona)->put(route('vacaciones.seleccion.catalogo.topes'), ['topes' => [$this->estacion->id => 0]])->assertSessionHasErrors('topes.'.$this->estacion->id);
    }

    public function test_command_creates_personal_accounts_without_printing_passwords(): void
    {
        Storage::fake('local');
        $e = Empleado::factory()->create(['estacion_id' => $this->estacion->id, 'correo' => 'persona.prueba@example.com']);
        Empleado::factory()->create(['estacion_id' => $this->estacion->id, 'correo' => null]);

        $this->artisan('vacaciones:crear-cuentas-personal')->expectsOutputToContain('Se crearon 1 cuentas.')->assertSuccessful();

        $user = User::query()->where('email', 'persona.prueba@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Personal'));
        $this->assertSame($e->id, $user->empleado_id);
        $this->artisan('vacaciones:crear-cuentas-personal')->expectsOutputToContain('Se crearon 0 cuentas.')->assertSuccessful();
    }
}
