<?php

namespace Tests\Feature;

use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "comisionados_visitantes" (Etapa 2 — Control de
 * Asistencia Diaria, see the approved plan). Mirrors
 * tests/Feature/AsistenciaCapturaTest.php's style/checklist per
 * .claude/rules/testing.md. Uses only dummy factory data.
 */
class ComisionadoVisitanteCrudTest extends TestCase
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

    private function storePayload(array $overrides = []): array
    {
        return array_merge([
            'fecha' => CarbonImmutable::today()->toDateString(),
            'nombre' => 'Persona Visitante De Prueba',
            'no_trabajador' => '12345',
            'direccion_origen' => 'Corporativo Mérida',
            'motivo' => 'Supervisión',
        ], $overrides);
    }

    // --- Authentication ----------------------------------------------------

    public function test_guest_is_redirected_from_visitante_store(): void
    {
        $response = $this->post(route('asistencia.captura.visitantes.store'), []);

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked_from_visitante_store(): void
    {
        $estacion = $this->estacionOperativa();
        $user = $this->estacionUser($estacion);

        $response = $this->actingAs($user)->post(route('asistencia.captura.visitantes.store'), $this->storePayload());

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization: Estación role ---------------------------------------

    public function test_estacion_user_can_create_visitante_for_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload());

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_visitantes', [
            'estacion_id' => $estacion->id,
            'nombre' => 'Persona Visitante De Prueba',
        ]);
    }

    /**
     * Same "ignored, not honored" defense-in-depth pattern as
     * AsistenciaCapturaTest::test_estacion_user_querystring_estacion_override_is_ignored_not_honored():
     * an Estación account can never target another station by tampering
     * with the 'estacion' field — resolveEstacionObjetivo() always uses
     * their own station regardless of client input, so the write lands on
     * their own station, never the other one.
     */
    public function test_estacion_user_create_estacion_override_is_ignored_not_honored(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->post(
            route('asistencia.captura.visitantes.store'),
            $this->storePayload(['estacion' => $otherStation->id]),
        );

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_visitantes', ['estacion_id' => $ownStation->id]);
        $this->assertDatabaseMissing('comisionados_visitantes', ['estacion_id' => $otherStation->id]);
    }

    public function test_estacion_user_can_update_and_delete_own_station_visitante(): void
    {
        $estacion = $this->estacionOperativa();
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $updateResponse = $this->put(route('asistencia.captura.visitantes.update', $visitante), [
            'nombre' => 'Nombre Actualizado',
        ]);
        $updateResponse->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_visitantes', ['id' => $visitante->id, 'nombre' => 'Nombre Actualizado']);

        $deleteResponse = $this->delete(route('asistencia.captura.visitantes.destroy', $visitante));
        $deleteResponse->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseMissing('comisionados_visitantes', ['id' => $visitante->id]);
    }

    public function test_estacion_user_cannot_update_another_station_visitante(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $otherStation->id,
            'fecha' => CarbonImmutable::today(),
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->put(route('asistencia.captura.visitantes.update', $visitante), ['nombre' => 'Intento']);

        $response->assertForbidden();
        $this->assertDatabaseHas('comisionados_visitantes', ['id' => $visitante->id, 'nombre' => $visitante->nombre]);
    }

    public function test_estacion_user_cannot_update_a_past_dated_visitante_of_their_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->subDays(3),
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.visitantes.update', $visitante), ['nombre' => 'Intento']);

        $response->assertForbidden();
    }

    // --- Authorization: Jefe de Zona (read-only) ----------------------------

    public function test_zone_chief_cannot_create_visitante(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->post(
            route('asistencia.captura.visitantes.store'),
            $this->storePayload(['estacion' => $estacion->id]),
        );

        $response->assertForbidden();
        $this->assertDatabaseCount('comisionados_visitantes', 0);
    }

    // --- Authorization: Administrador ---------------------------------------

    public function test_administrador_can_create_visitante_for_any_station_and_any_fecha(): void
    {
        $edificioZonalEste = $this->estacionOperativa(['is_operativa' => false, 'nombre' => 'Edificio Zonal Este']);
        $this->actingAsTwoFactorVerified($this->administrador());

        $pastDate = CarbonImmutable::today()->subDays(10);

        $response = $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload([
            'estacion' => $edificioZonalEste->id,
            'fecha' => $pastDate->toDateString(),
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('comisionados_visitantes', [
            'estacion_id' => $edificioZonalEste->id,
        ]);
    }

    public function test_administrador_can_update_a_past_dated_visitante(): void
    {
        $estacion = $this->estacionOperativa();
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->subDays(10),
        ]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('asistencia.captura.visitantes.update', $visitante), ['nombre' => 'Corregido']);

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_visitantes', ['id' => $visitante->id, 'nombre' => 'Corregido']);
    }

    // --- Validation ----------------------------------------------------------

    public function test_store_requires_nombre(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload(['nombre' => '']));

        $response->assertInvalid(['nombre']);
    }

    public function test_store_rejects_a_fecha_fin_before_fecha_inicio(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload([
            'fecha_inicio' => CarbonImmutable::today()->toDateString(),
            'fecha_fin' => CarbonImmutable::today()->subDay()->toDateString(),
        ]));

        $response->assertInvalid(['fecha_fin']);
    }

    // --- Mass-assignment safety ----------------------------------------------

    public function test_store_ignores_client_supplied_registrado_por(): void
    {
        $estacion = $this->estacionOperativa();
        $intruder = User::factory()->create();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload([
            'registrado_por' => $intruder->id,
        ]));

        $visitante = ComisionadoVisitante::firstOrFail();
        $this->assertSame($actor->id, $visitante->registrado_por);
        $this->assertSame($estacion->id, $visitante->estacion_id);
    }

    // --- Audit logging ---------------------------------------------------------

    public function test_creating_a_visitante_logs_a_created_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload());

        $visitante = ComisionadoVisitante::firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $visitante->id)
            ->where('subject_type', ComisionadoVisitante::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_updating_a_visitante_logs_an_updated_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);

        $this->put(route('asistencia.captura.visitantes.update', $visitante), ['nombre' => 'Otro Nombre']);

        $activity = Activity::query()
            ->where('subject_id', $visitante->id)
            ->where('subject_type', ComisionadoVisitante::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_deleting_a_visitante_logs_a_deleted_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));
        $visitante = ComisionadoVisitante::factory()->create([
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);

        $this->delete(route('asistencia.captura.visitantes.destroy', $visitante));

        $activity = Activity::query()
            ->where('subject_id', $visitante->id)
            ->where('subject_type', ComisionadoVisitante::class)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    // --- Happy path --------------------------------------------------------

    public function test_full_create_read_update_delete_cycle(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.visitantes.store'), $this->storePayload())
            ->assertRedirect(route('asistencia.captura.index'));

        $indexResponse = $this->get(route('asistencia.captura.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Persona Visitante De Prueba');

        $visitante = ComisionadoVisitante::firstOrFail();

        $this->put(route('asistencia.captura.visitantes.update', $visitante), ['nombre' => 'Actualizado Correctamente'])
            ->assertRedirect(route('asistencia.captura.index'));

        $this->assertDatabaseHas('comisionados_visitantes', ['id' => $visitante->id, 'nombre' => 'Actualizado Correctamente']);

        $this->delete(route('asistencia.captura.visitantes.destroy', $visitante))
            ->assertRedirect(route('asistencia.captura.index'));

        $this->assertDatabaseMissing('comisionados_visitantes', ['id' => $visitante->id]);
    }
}
