<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Estadísticas" -> Gasto energético (read-only report).
 * Dummy factory data only (.claude/rules/testing.md).
 */
class GastoEnergeticoTest extends TestCase
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

    /**
     * @param  array<int, float>  $montosPorMes
     */
    private function servicio(string $estacion, bool $agua, int $anio, array $montosPorMes, ?string $observaciones = null): ServicioEstacion
    {
        $servicio = ServicioEstacion::factory()
            ->when($agua, fn ($factory) => $factory->agua())
            ->create([
                'estacion_id' => Estacion::firstOrCreate(['nombre' => $estacion], ['orden' => 1])->id,
                'observaciones' => $observaciones,
            ]);

        foreach ($montosPorMes as $mes => $monto) {
            PagoServicio::factory()->create([
                'servicio_estacion_id' => $servicio->id,
                'anio' => $anio,
                'mes' => $mes,
                'monto' => $monto,
            ]);
        }

        return $servicio;
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('estadisticas.gasto-energetico.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked(): void
    {
        $response = $this->actingAs($this->userWithRole('Jefe de Zona'))->get(route('estadisticas.gasto-energetico.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_estacion_user_cannot_see_the_report(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('estadisticas.gasto-energetico.index'))->assertForbidden();
    }

    public function test_zone_chief_and_administrador_can_see_the_report(): void
    {
        foreach (['Jefe de Zona', 'Administrador'] as $role) {
            $this->actingAsTwoFactorVerified($this->userWithRole($role));

            $this->get(route('estadisticas.gasto-energetico.index'))->assertOk();
        }
    }

    public function test_the_route_is_not_captured_by_the_estadisticas_estacion_route(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->assertSame('/estadisticas/gasto-energetico', route('estadisticas.gasto-energetico.index', absolute: false));
        $this->get('/estadisticas/gasto-energetico')->assertOk()->assertSee('Gasto energético');
    }

    // --- Listing ------------------------------------------------------------

    public function test_shows_payments_totals_and_observations_for_the_latest_year_by_default(): void
    {
        $this->servicio('Bacalar', agua: false, anio: 2025, montosPorMes: [1 => 999.0]);
        $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 1000.0, 2 => 2500.5], observaciones: 'Medidor pendiente de CFE');
        $this->servicio('Tulum', agua: true, anio: 2026, montosPorMes: [1 => 300.0]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.index'))
            ->assertOk()
            ->assertSee('Gasto energético 2026', false)
            ->assertSee('Pago de servicio de energía eléctrica')
            ->assertSee('Pago de servicio de agua')
            ->assertSee('$3,501', false)   // energía: 1000 + 2500.5, sin decimales
            ->assertSee('$300', false)
            ->assertSee('Medidor pendiente de CFE')
            ->assertSee('Tulum');

        $this->get(route('estadisticas.gasto-energetico.index', ['anio' => 2025]))
            ->assertSee('Gasto energético 2025', false)
            ->assertSee('Bacalar')
            ->assertDontSee('$3,501', false);
    }

    public function test_a_zero_payment_and_a_missing_month_both_render_as_a_dash(): void
    {
        // Both services have some real payment so no KPI total is "$0".
        $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 0.0, 3 => 700.0]);
        $this->servicio('Tulum', agua: true, anio: 2026, montosPorMes: [2 => 50.0]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.index'))
            ->assertOk()
            ->assertDontSee('$0', false)
            ->assertSee('—', false);
    }

    public function test_renders_an_empty_state_when_nothing_has_been_imported(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.index'))
            ->assertOk()
            ->assertSee('Sin información cargada.');
    }

    // --- Validation ---------------------------------------------------------

    public function test_rejects_an_invalid_year(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.index', ['anio' => 'abc']))->assertSessionHasErrors('anio');
        $this->get(route('estadisticas.gasto-energetico.index', ['anio' => 1500]))->assertSessionHasErrors('anio');
    }

    // --- Captura por estación: acceso ----------------------------------------

    private function payload(int $anio, array $energia = [], array $agua = []): array
    {
        return [
            'anio' => $anio,
            'servicios' => [
                'energia_electrica' => array_merge(['proveedor' => '', 'contrato' => '', 'observaciones' => '', 'meses' => []], $energia),
                'agua' => array_merge(['proveedor' => '', 'contrato' => '', 'observaciones' => '', 'meses' => []], $agua),
            ],
        ];
    }

    public function test_guest_is_redirected_from_show_and_update(): void
    {
        $estacion = Estacion::factory()->create();

        $this->get(route('estadisticas.gasto-energetico.show', $estacion))->assertRedirect(route('login'));
        $this->put(route('estadisticas.gasto-energetico.update', $estacion), $this->payload(2026))->assertRedirect(route('login'));
    }

    public function test_estacion_user_cannot_view_or_update_any_station(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('estadisticas.gasto-energetico.show', $estacion))->assertForbidden();
        $this->put(route('estadisticas.gasto-energetico.update', $estacion), $this->payload(2026, ['meses' => [1 => 100]]))->assertForbidden();
        $this->assertSame(0, PagoServicio::count());
    }

    public function test_show_prefills_the_servicio_and_the_captured_months(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [3 => 1234.5], observaciones: 'Nota vigente');
        $servicio->update(['proveedor' => 'CFE PRUEBA', 'contrato' => 'C-123']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => 2026]))
            ->assertOk()
            ->assertSee('Gasto energético — Tulum', false)
            ->assertSee('CFE PRUEBA')
            ->assertSee('C-123')
            ->assertSee('Nota vigente')
            ->assertSee('value="1234.50"', false)
            ->assertSee('Capturado');
    }

    // --- Captura por estación: guardar ---------------------------------------

    public function test_zone_chief_can_capture_payments_and_servicio_data_and_it_is_audited(): void
    {
        $estacion = Estacion::factory()->create();
        $jefe = $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->put(route('estadisticas.gasto-energetico.update', $estacion), $this->payload(
            2026,
            ['proveedor' => 'CFE', 'contrato' => '796240', 'observaciones' => 'Medidor instalado', 'meses' => [1 => '1500.75', 2 => '0']],
            ['proveedor' => 'CAPA', 'meses' => [1 => '200']],
        ))->assertRedirect(route('estadisticas.gasto-energetico.show', ['estacion' => $estacion->id, 'anio' => 2026]))
            ->assertSessionHas('status');

        $energia = ServicioEstacion::where('estacion_id', $estacion->id)->where('tipo', 'energia_electrica')->firstOrFail();
        $this->assertSame('CFE', $energia->proveedor);
        $this->assertSame('Medidor instalado', $energia->observaciones);
        $this->assertSame(['1' => '1500.75', '2' => '0.00'], $energia->pagos()->orderBy('mes')->pluck('monto', 'mes')->all());
        $this->assertSame('CAPA', ServicioEstacion::where('estacion_id', $estacion->id)->where('tipo', 'agua')->firstOrFail()->proveedor);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PagoServicio::class,
            'event' => 'created',
            'causer_id' => $jefe->id,
        ]);
        $this->assertDatabaseHas('activity_log', ['subject_type' => ServicioEstacion::class, 'event' => 'created', 'causer_id' => $jefe->id]);
    }

    public function test_saving_updates_an_existing_payment_instead_of_duplicating_it_and_logs_the_change(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('estadisticas.gasto-energetico.update', $servicio->estacion_id), $this->payload(2026, ['meses' => [1 => '250']]))
            ->assertRedirect();

        $this->assertSame(1, PagoServicio::count());
        $this->assertSame('250.00', PagoServicio::firstOrFail()->monto);
        $this->assertDatabaseHas('activity_log', ['subject_type' => PagoServicio::class, 'event' => 'updated']);
    }

    public function test_blank_months_are_skipped_and_never_delete_or_create_payments(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->put(route('estadisticas.gasto-energetico.update', $servicio->estacion_id), $this->payload(2026, ['meses' => [1 => '', 2 => null]]))
            ->assertRedirect();

        $this->assertSame(1, PagoServicio::count());
        $this->assertSame('100.00', PagoServicio::firstOrFail()->monto);
    }

    public function test_an_untouched_servicio_the_station_does_not_have_is_not_created(): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->put(route('estadisticas.gasto-energetico.update', $estacion), $this->payload(2026, ['meses' => [1 => '100']]))->assertRedirect();

        $this->assertSame(1, ServicioEstacion::count());
    }

    public function test_the_target_station_comes_from_the_route_not_the_body(): void
    {
        $destino = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->put(
            route('estadisticas.gasto-energetico.update', $destino),
            $this->payload(2026, ['meses' => [1 => '100']]) + ['estacion_id' => $otra->id, 'servicio_estacion_id' => 999],
        )->assertRedirect();

        $this->assertSame($destino->id, ServicioEstacion::firstOrFail()->estacion_id);
    }

    // --- Captura por estación: validación ------------------------------------

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $base = fn (array $energia = []) => [
            'anio' => 2026,
            'servicios' => ['energia_electrica' => array_merge(['meses' => []], $energia)],
        ];

        return [
            'missing year' => [['servicios' => $base()['servicios']], 'anio'],
            'year out of range' => [['anio' => 1500] + $base(), 'anio'],
            'missing servicios' => [['anio' => 2026], 'servicios'],
            'unknown tipo de servicio' => [['anio' => 2026, 'servicios' => ['gas' => ['meses' => []]]], 'servicios'],
            'month 13' => [$base(['meses' => [13 => '10']]), 'servicios.energia_electrica.meses'],
            'negative amount' => [$base(['meses' => [1 => '-5']]), 'servicios.energia_electrica.meses.1'],
            'non numeric amount' => [$base(['meses' => [1 => 'mucho']]), 'servicios.energia_electrica.meses.1'],
            'amount over the column limit' => [$base(['meses' => [1 => '99999999999']]), 'servicios.energia_electrica.meses.1'],
            'proveedor too long' => [$base(['proveedor' => str_repeat('a', 256)]), 'servicios.energia_electrica.proveedor'],
            'observaciones too long' => [$base(['observaciones' => str_repeat('a', 5001)]), 'servicios.energia_electrica.observaciones'],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     */
    public function test_rejects_invalid_payloads_without_persisting_anything(array $payload, string $errorKey): void
    {
        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->put(route('estadisticas.gasto-energetico.update', $estacion), $payload)->assertSessionHasErrors($errorKey);

        $this->assertSame(0, ServicioEstacion::count());
        $this->assertSame(0, PagoServicio::count());
    }

    // --- Eliminar --------------------------------------------------------------

    public function test_zone_chief_cannot_delete_a_payment_and_does_not_see_the_button(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0]);
        $pago = $servicio->pagos()->firstOrFail();
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => 2026]))
            ->assertOk()
            ->assertDontSee('eliminar-pago-'.$pago->id, false);

        $this->delete(route('estadisticas.gasto-energetico.destroy', ['estacion' => $servicio->estacion_id, 'pago' => $pago->id]))
            ->assertForbidden();
        $this->assertModelExists($pago);
    }

    public function test_administrador_sees_the_button_and_can_delete_a_payment_and_it_is_audited(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0, 2 => 200.0]);
        $pago = $servicio->pagos()->where('mes', 1)->firstOrFail();
        $admin = $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->get(route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => 2026]))
            ->assertSee('eliminar-pago-'.$pago->id, false);

        $this->delete(route('estadisticas.gasto-energetico.destroy', ['estacion' => $servicio->estacion_id, 'pago' => $pago->id]))
            ->assertRedirect(route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => 2026]))
            ->assertSessionHas('status');

        $this->assertModelMissing($pago);
        $this->assertSame(1, PagoServicio::count());
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PagoServicio::class,
            'subject_id' => $pago->id,
            'event' => 'deleted',
            'causer_id' => $admin->id,
        ]);
    }

    public function test_deleting_a_payment_through_another_stations_url_is_a_404(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0]);
        $otra = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->delete(route('estadisticas.gasto-energetico.destroy', ['estacion' => $otra->id, 'pago' => $servicio->pagos()->firstOrFail()->id]))
            ->assertNotFound();

        $this->assertSame(1, PagoServicio::count());
    }

    // --- Listing links ----------------------------------------------------------

    public function test_the_summary_links_each_station_to_its_edit_screen(): void
    {
        $servicio = $this->servicio('Tulum', agua: false, anio: 2026, montosPorMes: [1 => 100.0]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('estadisticas.gasto-energetico.index'))
            ->assertSee(route('estadisticas.gasto-energetico.show', ['estacion' => $servicio->estacion_id, 'anio' => 2026]), false)
            ->assertSee('Editar');
    }

    // --- Navigation ---------------------------------------------------------

    public function test_estadisticas_menu_offers_gasto_energetico_only_to_zone_roles(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('dashboard'))
            ->assertSee(route('estadisticas.index'), false)
            ->assertSee(route('estadisticas.gasto-energetico.index'), false);

        $estacion = Estacion::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('asistencia.captura.index'))
            ->assertSee(route('estadisticas.index'), false)
            ->assertDontSee(route('estadisticas.gasto-energetico.index'), false);
    }
}
