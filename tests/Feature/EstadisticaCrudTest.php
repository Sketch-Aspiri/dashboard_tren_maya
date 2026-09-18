<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the "Estadísticas" module (flujo de pasajeros /
 * boletos vendidos por estación, ver el plan aprobado). Exercises the
 * checklist required by .claude/rules/testing.md: authentication,
 * authorization per role (including station scoping), validation,
 * mass-assignment safety, audit logging, and the happy path. Uses only
 * dummy factory data — the real KPI/report shape is still pending the Jefe
 * de Zona (CLAUDE.md).
 */
class EstadisticaCrudTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function zoneChief(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Jefe de Zona');

        return $user;
    }

    private function administrador(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Administrador');

        return $user;
    }

    private function estacionUser(Estacion $estacion): User
    {
        $user = User::factory()->create(['estacion_id' => $estacion->id]);
        $user->assignRole('Estación');

        return $user;
    }

    private function estacionOperativa(array $overrides = []): Estacion
    {
        return Estacion::factory()->create(array_merge(['is_operativa' => true], $overrides));
    }

    private function payloadParaUnDia(int $anio, int $mes, int $dia, int $abordan, int $boletos): array
    {
        return [
            'anio' => $anio,
            'mes' => $mes,
            'dias' => [
                $dia => ['dia' => $dia, 'abordan' => $abordan, 'boletos_vendidos' => $boletos],
            ],
        ];
    }

    // --- Authentication --------------------------------------------------

    public function test_guest_is_redirected_from_index(): void
    {
        $response = $this->get(route('estadisticas.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_show(): void
    {
        $estacion = $this->estacionOperativa();

        $response = $this->get(route('estadisticas.show', $estacion));

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked_from_index(): void
    {
        $user = $this->administrador();

        $response = $this->actingAs($user)->get(route('estadisticas.index'));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization: Administrador / Jefe de Zona ----------------------

    public function test_administrador_can_view_any_station(): void
    {
        $estacionA = $this->estacionOperativa();
        $estacionB = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->get(route('estadisticas.show', $estacionA))->assertOk();
        $this->get(route('estadisticas.show', $estacionB))->assertOk();
    }

    public function test_zone_chief_can_view_any_station(): void
    {
        $estacionA = $this->estacionOperativa();
        $estacionB = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $this->get(route('estadisticas.show', $estacionA))->assertOk();
        $this->get(route('estadisticas.show', $estacionB))->assertOk();
    }

    public function test_administrador_can_create_and_update_any_station(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(
            route('estadisticas.update', $estacion),
            $this->payloadParaUnDia(2026, 3, 10, 150, 140),
        );

        $response->assertRedirect(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 3]));
        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-03-10')->firstOrFail();
        $this->assertSame($estacion->id, $registro->estacion_id);
        $this->assertSame(150, $registro->abordan);
        $this->assertSame(140, $registro->boletos_vendidos);
    }

    public function test_zone_chief_can_create_and_update_any_station(): void
    {
        // Unlike RegistroDiarioPolicy, Jefe de Zona both reads AND writes
        // estadísticas (decision recorded in EstadisticaDiariaPolicy).
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->put(
            route('estadisticas.update', $estacion),
            $this->payloadParaUnDia(2026, 4, 5, 200, 190),
        );

        $response->assertRedirect(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 4]));
        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-04-05')->first();
        $this->assertNotNull($registro);
        $this->assertSame($estacion->id, $registro->estacion_id);
    }

    // --- Authorization: Estación role, scoped to own station --------------

    public function test_estacion_user_can_view_and_update_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->get(route('estadisticas.show', $estacion))->assertOk();

        $response = $this->put(
            route('estadisticas.update', $estacion),
            $this->payloadParaUnDia(2026, 5, 12, 80, 75),
        );

        $response->assertRedirect(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 5]));
        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-05-12')->first();
        $this->assertNotNull($registro);
        $this->assertSame($estacion->id, $registro->estacion_id);
    }

    public function test_estacion_user_cannot_view_a_different_station(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->get(route('estadisticas.show', $otherStation));

        $response->assertForbidden();
    }

    public function test_estacion_user_update_url_override_is_ignored_not_honored(): void
    {
        // Same "never trust a client-supplied estación id" rule as
        // AsistenciaCapturaService::resolveEstacionObjetivo() (see
        // AsistenciaCapturaTest::test_estacion_user_querystring_estacion_override_is_ignored_not_honored()):
        // an "Estación" account can only ever write its own station, so
        // hitting a different station's {estacion} URL self-heals to the
        // caller's own station instead of writing to (or leaking the
        // existence of) the other one.
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->put(
            route('estadisticas.update', $otherStation),
            $this->payloadParaUnDia(2026, 6, 1, 10, 10),
        );

        $response->assertRedirect(route('estadisticas.show', ['estacion' => $ownStation->id, 'anio' => 2026, 'mes' => 6]));
        $this->assertDatabaseHas('estadisticas_diarias', ['estacion_id' => $ownStation->id, 'abordan' => 10]);
        $this->assertDatabaseMissing('estadisticas_diarias', ['estacion_id' => $otherStation->id]);
    }

    // --- Authorization: delete is Administrador-only -----------------------

    public function test_administrador_can_delete_a_registro(): void
    {
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->delete(route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]));

        $response->assertRedirect();
        $this->assertDatabaseMissing('estadisticas_diarias', ['id' => $registro->id]);
    }

    public function test_zone_chief_cannot_delete_a_registro(): void
    {
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->delete(route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]));

        $response->assertForbidden();
        $this->assertDatabaseHas('estadisticas_diarias', ['id' => $registro->id]);
    }

    public function test_estacion_user_cannot_delete_a_registro_even_for_its_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->delete(route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]));

        $response->assertForbidden();
        $this->assertDatabaseHas('estadisticas_diarias', ['id' => $registro->id]);
    }

    public function test_destroy_returns_404_when_registro_does_not_belong_to_the_url_station(): void
    {
        // The {estacion} URL segment and {registro}'s actual estacion_id
        // must match — otherwise this would let a caller delete a real
        // registro by guessing its id while authorizing against an
        // unrelated station's URL. Administrador is used here (its
        // "delete" ability always passes) so this test isolates the
        // abort_unless() station-match guard from the Policy check.
        $estacionA = $this->estacionOperativa();
        $estacionB = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create(['estacion_id' => $estacionB->id]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->delete(route('estadisticas.destroy', ['estacion' => $estacionA->id, 'registro' => $registro->id]));

        $response->assertNotFound();
        $this->assertDatabaseHas('estadisticas_diarias', ['id' => $registro->id]);
    }

    // --- Validation ----------------------------------------------------------

    public function test_update_rejects_negative_abordan(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 3,
            'dias' => [10 => ['dia' => 10, 'abordan' => -5, 'boletos_vendidos' => 10]],
        ]);

        $response->assertInvalid(['dias.10.abordan']);
    }

    public function test_update_rejects_negative_boletos_vendidos(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 3,
            'dias' => [10 => ['dia' => 10, 'abordan' => 5, 'boletos_vendidos' => -1]],
        ]);

        $response->assertInvalid(['dias.10.boletos_vendidos']);
    }

    public function test_update_rejects_a_dia_outside_1_31(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 3,
            'dias' => [0 => ['dia' => 32, 'abordan' => 5, 'boletos_vendidos' => 5]],
        ]);

        $response->assertInvalid(['dias.0.dia']);
    }

    public function test_update_rejects_a_dia_that_does_not_exist_for_the_month(): void
    {
        // 31 de abril doesn't exist — passes the between:1,31 rule but
        // fails checkdate().
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 4,
            'dias' => [0 => ['dia' => 31, 'abordan' => 5, 'boletos_vendidos' => 5]],
        ]);

        $response->assertInvalid(['dias.0.dia']);
    }

    // --- Mass-assignment safety --------------------------------------------

    public function test_update_ignores_client_supplied_estacion_id_and_registrado_por(): void
    {
        $estacion = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $intruder = User::factory()->create();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 7,
            'dias' => [15 => [
                'dia' => 15,
                'abordan' => 30,
                'boletos_vendidos' => 25,
                'estacion_id' => $otherStation->id,
                'registrado_por' => $intruder->id,
            ]],
        ]);

        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-07-15')->firstOrFail();

        $this->assertSame($estacion->id, $registro->estacion_id);
        $this->assertSame($actor->id, $registro->registrado_por);
    }

    // --- Audit logging -------------------------------------------------------

    public function test_creating_a_registro_logs_a_created_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $actor = $this->actingAsTwoFactorVerified($this->administrador());

        $this->put(route('estadisticas.update', $estacion), $this->payloadParaUnDia(2026, 8, 3, 40, 35));

        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-08-03')->firstOrFail();

        $activity = Activity::query()->where('subject_id', $registro->id)
            ->where('subject_type', EstadisticaDiaria::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_updating_a_registro_a_second_time_logs_an_updated_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $actor = $this->actingAsTwoFactorVerified($this->administrador());

        $this->put(route('estadisticas.update', $estacion), $this->payloadParaUnDia(2026, 9, 1, 50, 45));
        $this->put(route('estadisticas.update', $estacion), $this->payloadParaUnDia(2026, 9, 1, 60, 55));

        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-09-01')->firstOrFail();

        $activity = Activity::query()->where('subject_id', $registro->id)
            ->where('subject_type', EstadisticaDiaria::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_deleting_a_registro_logs_a_deleted_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create(['estacion_id' => $estacion->id]);
        $actor = $this->actingAsTwoFactorVerified($this->administrador());

        $this->delete(route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]));

        $activity = Activity::query()->where('subject_id', $registro->id)
            ->where('subject_type', EstadisticaDiaria::class)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    // --- Happy path: full create -> read -> update -> delete cycle --------

    public function test_full_crud_cycle_persists_expected_state(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        // Create.
        $this->put(route('estadisticas.update', $estacion), $this->payloadParaUnDia(2026, 10, 20, 300, 280))
            ->assertRedirect(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 10]));

        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-10-20')->firstOrFail();
        $this->assertSame(300, $registro->abordan);
        $this->assertSame(280, $registro->boletos_vendidos);

        // Read.
        $showResponse = $this->get(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 10]));
        $showResponse->assertOk();
        $showResponse->assertViewHas('registros', fn ($registros) => $registros->get(20)?->id === $registro->id);

        // Update (resubmitting the same day updates in place, doesn't duplicate).
        $this->put(route('estadisticas.update', $estacion), $this->payloadParaUnDia(2026, 10, 20, 310, 290))
            ->assertRedirect();
        $this->assertDatabaseCount('estadisticas_diarias', 1);
        $registro->refresh();
        $this->assertSame(310, $registro->abordan);
        $this->assertSame(290, $registro->boletos_vendidos);

        // Delete.
        $this->delete(route('estadisticas.destroy', ['estacion' => $estacion->id, 'registro' => $registro->id]))
            ->assertRedirect();
        $this->assertDatabaseMissing('estadisticas_diarias', ['id' => $registro->id]);
    }

    public function test_days_without_captured_data_are_never_persisted(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->put(route('estadisticas.update', $estacion), [
            'anio' => 2026,
            'mes' => 11,
            'dias' => [
                1 => ['dia' => 1, 'abordan' => null, 'boletos_vendidos' => null],
                2 => ['dia' => 2, 'abordan' => 100, 'boletos_vendidos' => 90],
            ],
        ]);

        $this->assertDatabaseCount('estadisticas_diarias', 1);
        $registro = EstadisticaDiaria::query()->whereDate('fecha', '2026-11-02')->first();
        $this->assertNotNull($registro);
        $this->assertSame(100, $registro->abordan);
    }

    // --- Rendering (regression) --------------------------------------------

    public function test_show_renders_real_abordan_and_boletos_inputs_with_their_values(): void
    {
        // Regression for a nested-double-quotes bug: old("dias.$dia.abordan", ...)
        // written directly inside an <x-text-input value="..."> attribute
        // broke Blade's component-tag attribute parser, so <x-text-input>
        // was left uncompiled as literal text instead of a real <input> —
        // the column appeared empty because there was no <input> element at
        // all, not because the value was missing.
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => '2026-12-15',
            'abordan' => 123,
            'boletos_vendidos' => 456,
        ]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 12]));

        $response->assertOk();
        $response->assertDontSee('<x-text-input', false);
        $content = $response->getContent();
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="dias\[15\]\[abordan\]"[^>]*value="123"/',
            $content,
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*name="dias\[15\]\[boletos_vendidos\]"[^>]*value="456"/',
            $content,
        );
    }

    public function test_delete_form_is_not_nested_inside_the_guardar_mes_form(): void
    {
        // Regression: the "Eliminar" form used to be nested inside the
        // "Guardar mes" <form>, which HTML doesn't allow — the browser
        // silently drops the inner <form> tag, its @method('DELETE') field
        // ends up floating inside the OUTER form, and clicking a per-row
        // delete button actually submits the outer "Guardar mes" form as a
        // DELETE instead of a PUT (MethodNotAllowedHttpException in
        // production). The real delete <form> must be a top-level sibling,
        // rendered AFTER the "Guardar mes" form's closing </form> tag —
        // this test fails if someone reintroduces the nested form.
        $estacion = $this->estacionOperativa();
        $registro = EstadisticaDiaria::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => '2026-12-15',
        ]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('estadisticas.show', ['estacion' => $estacion->id, 'anio' => 2026, 'mes' => 12]));

        $response->assertOk();
        $content = $response->getContent();

        $formIdAttribute = 'id="eliminar-registro-'.$registro->id.'"';
        $this->assertStringContainsString($formIdAttribute, $content);
        $this->assertStringContainsString('value="DELETE"', $content);

        // Locate the "Guardar mes" submit button, then the next </form>
        // after it — that is the closing tag of the "Guardar mes" form.
        $guardarMesButtonPos = strpos($content, __('Guardar mes'));
        $this->assertNotFalse($guardarMesButtonPos, 'No se encontró el botón "Guardar mes" en la respuesta.');

        $guardarMesClosingFormPos = strpos($content, '</form>', $guardarMesButtonPos);
        $this->assertNotFalse($guardarMesClosingFormPos, 'No se encontró el </form> que cierra "Guardar mes".');

        $eliminarFormPos = strpos($content, $formIdAttribute);
        $this->assertGreaterThan(
            $guardarMesClosingFormPos,
            $eliminarFormPos,
            'El <form> de eliminar debe aparecer DESPUÉS de que cierre el <form> de "Guardar mes", nunca anidado dentro de él.',
        );
    }
}
