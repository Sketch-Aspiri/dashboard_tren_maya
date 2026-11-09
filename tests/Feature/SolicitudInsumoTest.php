<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\SolicitudInsumoService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Recursos materiales" -> Solicitud de insumos.
 * Dummy factory data only (.claude/rules/testing.md).
 */
class SolicitudInsumoTest extends TestCase
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
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        return array_merge([
            'tipo_servicio' => '1er escalón',
            'lugar_trabajo' => 'Bacalar',
            'justificacion_general' => 'Mantenimiento mensual',
            'partidas' => [
                ['cantidad' => '3', 'unidad' => 'Pieza', 'descripcion' => 'Foco LED 12W', 'justificacion' => 'Reposición'],
            ],
        ], $override);
    }

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $solicitud = SolicitudInsumo::factory()->create();

        $this->get(route('insumos.index'))->assertRedirect(route('login'));
        $this->get(route('insumos.show', $solicitud))->assertRedirect(route('login'));
        $this->put(route('insumos.update', $solicitud), [])->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked(): void
    {
        $response = $this->actingAs($this->userWithRole('Jefe de Zona'))->get(route('insumos.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_zone_chief_sees_the_board_and_estacion_is_redirected_to_its_own(): void
    {
        $estacion = Estacion::factory()->create();

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->get(route('insumos.index'))->assertOk()->assertSee($estacion->nombre);

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $this->get(route('insumos.index'))->assertRedirect();
    }

    // --- Scoping -------------------------------------------------------------

    public function test_estacion_cannot_open_or_edit_another_estacion_solicitud(): void
    {
        $suya = Estacion::factory()->create();
        $ajena = SolicitudInsumo::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('insumos.show', $ajena))->assertForbidden();
        $this->put(route('insumos.update', $ajena), $this->payload())->assertForbidden();
        $this->get(route('insumos.estacion', $ajena->estacion))->assertForbidden();
    }

    // --- Captura ------------------------------------------------------------

    public function test_estacion_creates_saves_and_sends_its_solicitud_and_it_is_logged(): void
    {
        $estacion = Estacion::factory()->create();
        $usuario = $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->get(route('insumos.estacion', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 12]))->assertRedirect();
        $solicitud = SolicitudInsumo::query()->firstOrFail();
        $this->assertSame('borrador', $solicitud->estado);

        $this->put(route('insumos.update', $solicitud), $this->payload())->assertRedirect(route('insumos.show', $solicitud));
        $this->assertDatabaseHas('solicitud_insumo_partidas', ['solicitud_insumo_id' => $solicitud->id, 'descripcion' => 'Foco LED 12W']);

        $this->post(route('insumos.enviar', $solicitud))->assertRedirect(route('insumos.show', $solicitud));
        $this->assertSame('enviada', $solicitud->fresh()->estado);

        $this->assertTrue(Activity::query()->where('log_name', 'solicitud_insumo')->where('causer_id', $usuario->id)->where('description', 'enviada')->exists());

        // Enviada: la estación ya no puede editar.
        $this->put(route('insumos.update', $solicitud), $this->payload())->assertForbidden();
    }

    public function test_show_renders_for_estacion_edit_and_zone_review(): void
    {
        $estacion = Estacion::factory()->create();
        $borrador = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $enviada = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $estacion->id, 'mes' => 11]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $enviada->id, 'descripcion' => 'Cinta aislante']);

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));
        $this->get(route('insumos.show', $borrador))->assertOk()->assertSee('Guardar borrador');
        $this->get(route('insumos.show', $enviada))->assertOk()->assertSee('Cinta aislante')->assertDontSee('Guardar borrador');

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->get(route('insumos.show', $enviada))->assertOk()->assertSee('Aprobar')->assertSee('Devolver a la estación');
    }

    public function test_estacion_cannot_send_an_empty_solicitud(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->post(route('insumos.enviar', $solicitud))->assertSessionHasErrors('partidas');
        $this->assertSame('borrador', $solicitud->fresh()->estado);
    }

    public function test_validation_rejects_bad_partidas_and_ignores_price_fields(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->put(route('insumos.update', $solicitud), $this->payload(['partidas' => []]))->assertInvalid('partidas');
        $this->put(route('insumos.update', $solicitud), $this->payload([
            'partidas' => [['cantidad' => '0', 'unidad' => '', 'descripcion' => '']],
        ]))->assertInvalid(['partidas.0.cantidad', 'partidas.0.unidad', 'partidas.0.descripcion']);

        $this->put(route('insumos.update', $solicitud), $this->payload([
            'partidas' => [['cantidad' => '1', 'unidad' => 'Pieza', 'descripcion' => 'Cable', 'precio_unitario' => '9999']],
        ]))->assertRedirect();
        $this->assertNull($solicitud->partidas()->first()->precio_unitario);
    }

    // --- Revisión de la Zona ------------------------------------------------

    public function test_estacion_cannot_review(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $estacion->id]));

        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'aprobar'])->assertForbidden();
    }

    public function test_zone_chief_returns_with_comment_and_estacion_can_edit_again(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $estacion->id]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'devolver'])->assertInvalid('comentario_zona');
        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'devolver', 'comentario_zona' => 'Falta justificar'])->assertRedirect();

        $this->assertSame('observada', $solicitud->fresh()->estado);
        $this->assertTrue($solicitud->fresh()->esEditablePorEstacion());
    }

    public function test_approving_requires_price_and_partida_presupuestal_then_computes_totals(): void
    {
        $estacion = Estacion::factory()->create();
        $solicitud = SolicitudInsumo::factory()->enviada()->create(['estacion_id' => $estacion->id]);
        $partida = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'cantidad' => 10]);
        $this->actingAsTwoFactorVerified($zona = $this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.revisar', $solicitud), ['accion' => 'aprobar'])
            ->assertInvalid(['partidas.'.$partida->id.'.precio_unitario']);

        $this->post(route('insumos.revisar', $solicitud), [
            'accion' => 'aprobar',
            'partidas' => [$partida->id => ['precio_unitario' => '100', 'partida_presupuestal' => '35101']],
        ])->assertRedirect();

        $solicitud->refresh();
        $this->assertSame('aprobada', $solicitud->estado);
        $this->assertSame($zona->id, $solicitud->revisada_por);

        $totales = app(SolicitudInsumoService::class)->totales($solicitud->load('partidas'));
        $this->assertSame(1000.0, $totales['subtotal']);
        $this->assertSame(80.0, $totales['iva']);
        $this->assertSame(1080.0, $totales['total']);
        $this->assertFalse($totales['excedeTope']);
    }

    // --- Reglas del servicio --------------------------------------------------

    public function test_mes_objetivo_rolls_to_next_month_from_day_15(): void
    {
        $service = app(SolicitudInsumoService::class);

        $this->assertSame(['anio' => 2026, 'mes' => 11], $service->mesObjetivo(Carbon::create(2026, 11, 14)));
        $this->assertSame(['anio' => 2026, 'mes' => 12], $service->mesObjetivo(Carbon::create(2026, 11, 15)));
        $this->assertSame(['anio' => 2027, 'mes' => 1], $service->mesObjetivo(Carbon::create(2026, 12, 20)));
    }

    public function test_price_deviating_more_than_30_percent_from_history_is_flagged(): void
    {
        $historica = SolicitudInsumo::factory()->create(['mes' => 11, 'estado' => SolicitudInsumo::APROBADA]);
        SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $historica->id, 'descripcion' => 'Cable', 'precio_unitario' => 100]);

        $actual = SolicitudInsumo::factory()->create(['mes' => 12]);
        $normal = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $actual->id, 'numero' => 1, 'descripcion' => 'Cable', 'precio_unitario' => 120]);
        $alto = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $actual->id, 'numero' => 2, 'descripcion' => 'Cable', 'precio_unitario' => 140]);

        $atipicas = app(SolicitudInsumoService::class)->partidasAtipicas($actual->load('partidas'));

        $this->assertNotContains($normal->id, $atipicas);
        $this->assertContains($alto->id, $atipicas);
    }

    public function test_catalogo_endpoint_requires_auth_and_returns_envelope(): void
    {
        $this->getJson(route('insumos.catalogo.buscar'))->assertUnauthorized();

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->getJson(route('insumos.catalogo.buscar', ['q' => 'x']))
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
