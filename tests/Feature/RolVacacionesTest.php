<?php

namespace Tests\Feature;

use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\User;
use App\Models\Vacacionista;
use App\Models\VacacionPeriodo;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Agenda Zona Oriente" -> Rol de vacaciones (read-only
 * listing). Dummy factory data only (.claude/rules/testing.md).
 */
class RolVacacionesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    private function vacacionista(array $attributes = [], array $periodos = []): Vacacionista
    {
        $vacacionista = Vacacionista::factory()->create($attributes);

        foreach ($periodos as [$inicio, $termino, $dias, $trimestre]) {
            VacacionPeriodo::factory()->create([
                'vacacionista_id' => $vacacionista->id,
                'trimestre' => $trimestre,
                'fecha_inicio' => $inicio,
                'fecha_termino' => $termino,
                'dias_solicitados' => $dias,
            ]);
        }

        return $vacacionista;
    }

    // --- Authentication ---------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('agenda.vacaciones.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_cannot_see_the_rol(): void
    {
        $response = $this->actingAs($this->userWithRole('Jefe de Zona'))->get(route('agenda.vacaciones.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization ----------------------------------------------------

    public function test_estacion_user_cannot_see_the_rol(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('agenda.vacaciones.index'))->assertForbidden();
    }

    public function test_zone_chief_and_administrador_can_see_the_rol(): void
    {
        $this->vacacionista(['nombre_completo' => 'PERSONA VISIBLE']);

        foreach (['Jefe de Zona', 'Administrador'] as $role) {
            $this->actingAsTwoFactorVerified($this->userWithRole($role));

            $this->get(route('agenda.vacaciones.index'))
                ->assertOk()
                ->assertSee('PERSONA VISIBLE');
        }
    }

    // --- Listing ----------------------------------------------------------

    public function test_lists_periods_and_totals_for_the_latest_year_by_default(): void
    {
        $this->vacacionista(['anio' => 2025, 'nombre_completo' => 'SOLO EN 2025']);
        $this->vacacionista(['anio' => 2026, 'nombre_completo' => 'PERSONA 2026', 'dias_otorgados' => 20], [
            ['2026-02-02', '2026-02-06', 5, 1],
            ['2026-10-05', '2026-10-09', 5, 4],
            ['2026-11-17', '2026-11-23', 5, 4],
        ]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index'))
            ->assertOk()
            ->assertSee('PERSONA 2026')
            ->assertDontSee('SOLO EN 2025')
            ->assertSee('2 feb – 6 feb', false)
            ->assertSee('17 nov – 23 nov', false);

        $this->get(route('agenda.vacaciones.index', ['anio' => 2025]))
            ->assertOk()
            ->assertSee('SOLO EN 2025')
            ->assertDontSee('PERSONA 2026');
    }

    public function test_links_to_the_personal_record_when_the_empleado_is_linked(): void
    {
        $empleado = Empleado::factory()->create();
        $this->vacacionista(['empleado_id' => $empleado->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->get(route('agenda.vacaciones.index'))
            ->assertSee(route('agenda.personal.show', $empleado->id), false);
    }

    public function test_filters_by_estacion(): void
    {
        $bacalar = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum']);
        $this->vacacionista(['nombre_completo' => 'DE BACALAR', 'estacion_id' => $bacalar->id]);
        $this->vacacionista(['nombre_completo' => 'DE TULUM', 'estacion_id' => $tulum->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index', ['estacion_id' => $tulum->id]))
            ->assertSee('DE TULUM')
            ->assertDontSee('DE BACALAR');
    }

    public function test_filters_by_month_including_periods_that_span_two_months(): void
    {
        $this->vacacionista(['nombre_completo' => 'CRUZA MES'], [['2026-04-27', '2026-05-04', 6, 2]]);
        $this->vacacionista(['nombre_completo' => 'SOLO FEBRERO'], [['2026-02-02', '2026-02-06', 5, 1]]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index', ['mes' => 5]))
            ->assertSee('CRUZA MES')
            ->assertDontSee('SOLO FEBRERO');
    }

    public function test_searches_by_name_number_or_puesto_and_escapes_wildcards(): void
    {
        $this->vacacionista(['nombre_completo' => 'ANA LOPEZ', 'no_empleado' => '777', 'denominacion_puesto' => 'Taquillera']);
        $this->vacacionista(['nombre_completo' => 'LUIS PEREZ', 'no_empleado' => '888', 'denominacion_puesto' => 'Gerente']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index', ['q' => 'lopez']))->assertSee('ANA LOPEZ')->assertDontSee('LUIS PEREZ');
        $this->get(route('agenda.vacaciones.index', ['q' => '888']))->assertSee('LUIS PEREZ')->assertDontSee('ANA LOPEZ');
        $this->get(route('agenda.vacaciones.index', ['q' => 'Taquillera']))->assertSee('ANA LOPEZ')->assertDontSee('LUIS PEREZ');
        $this->get(route('agenda.vacaciones.index', ['q' => '%']))->assertDontSee('ANA LOPEZ')->assertDontSee('LUIS PEREZ');
    }

    public function test_pagination_footer_matches_personal_and_keeps_filters(): void
    {
        Vacacionista::factory()->count(30)->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index', ['anio' => 2026]))
            ->assertOk()
            ->assertSee('Mostrando 1–25 de 30')
            ->assertSee('Página 1 de 2')
            ->assertSee('Siguiente')
            ->assertSee('anio=2026&amp;page=2', false);

        $this->get(route('agenda.vacaciones.index', ['page' => 2]))
            ->assertSee('Mostrando 26–30 de 30')
            ->assertSee('Página 2 de 2');
    }

    // --- Validation -------------------------------------------------------

    public function test_rejects_invalid_filters(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index', ['mes' => 13]))->assertSessionHasErrors('mes');
        $this->get(route('agenda.vacaciones.index', ['estacion_id' => 999999]))->assertSessionHasErrors('estacion_id');
        $this->get(route('agenda.vacaciones.index', ['anio' => 'abc']))->assertSessionHasErrors('anio');
    }

    // --- Navigation -------------------------------------------------------

    public function test_agenda_menu_lists_personal_and_rol_de_vacaciones_for_zone_chief_only(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('agenda.vacaciones.index'))
            ->assertSee(route('agenda.personal.index'), false)
            ->assertSee(route('agenda.vacaciones.index'), false);

        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('asistencia.captura.index'))
            ->assertDontSee(route('agenda.vacaciones.index'), false)
            ->assertDontSee(route('agenda.personal.index'), false);
    }
}
