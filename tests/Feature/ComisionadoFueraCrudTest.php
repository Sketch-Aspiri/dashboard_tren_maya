<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Models\ComisionadoFuera;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "comisionados_fuera" (Etapa 2 — Control de
 * Asistencia Diaria, see the approved plan). Mirrors
 * tests/Feature/AsistenciaCapturaTest.php's style/checklist per
 * .claude/rules/testing.md. Uses only dummy factory data.
 */
class ComisionadoFueraCrudTest extends TestCase
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

    private function empleadoActivo(Estacion $estacion, array $overrides = []): Empleado
    {
        return Empleado::factory()->create(array_merge([
            'estacion_id' => $estacion->id,
            'estatus' => EmpleadoEstatus::Activo->value,
        ], $overrides));
    }

    private function storePayload(int $empleadoId, array $overrides = []): array
    {
        return array_merge([
            'fecha' => CarbonImmutable::today()->toDateString(),
            'empleado_id' => $empleadoId,
            'coordinacion_destino' => 'CGOFP',
            'ubicacion_destino' => 'Corporativo Mérida',
            'motivo' => 'Reunión de coordinación',
        ], $overrides);
    }

    // --- Authentication ----------------------------------------------------

    public function test_guest_is_redirected_from_comisionado_fuera_store(): void
    {
        $response = $this->post(route('asistencia.captura.comisionados.store'), []);

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked_from_comisionado_fuera_store(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $user = $this->estacionUser($estacion);

        $response = $this->actingAs($user)->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization: Estación role ---------------------------------------

    public function test_estacion_user_can_create_comisionado_fuera_for_own_station_roster(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id));

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_fuera', [
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
        ]);
    }

    /**
     * Same "ignored, not honored" defense-in-depth pattern as
     * AsistenciaCapturaTest::test_estacion_user_querystring_estacion_override_is_ignored_not_honored():
     * an Estación account can never target another station by tampering
     * with the 'estacion' field. Here the roster check then independently
     * rejects the other station's empleado_id against the (correctly
     * resolved) own station.
     */
    public function test_estacion_user_create_estacion_override_is_ignored_not_honored(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $otherEmpleado = $this->empleadoActivo($otherStation);
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->post(
            route('asistencia.captura.comisionados.store'),
            $this->storePayload($otherEmpleado->id, ['estacion' => $otherStation->id]),
        );

        $response->assertInvalid(['empleado_id']);
        $this->assertDatabaseCount('comisionados_fuera', 0);
    }

    public function test_estacion_user_cannot_create_comisionado_fuera_with_an_empleado_from_another_station(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $otherEmpleado = $this->empleadoActivo($otherStation);
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($otherEmpleado->id));

        $response->assertInvalid(['empleado_id']);
        $this->assertDatabaseCount('comisionados_fuera', 0);
    }

    public function test_estacion_user_can_update_and_delete_own_station_comisionado_fuera(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $updateResponse = $this->put(route('asistencia.captura.comisionados.update', $comisionado), [
            'empleado_id' => $empleado->id,
            'coordinacion_destino' => 'CGGIF',
        ]);
        $updateResponse->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_fuera', ['id' => $comisionado->id, 'coordinacion_destino' => 'CGGIF']);

        $deleteResponse = $this->delete(route('asistencia.captura.comisionados.destroy', $comisionado));
        $deleteResponse->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseMissing('comisionados_fuera', ['id' => $comisionado->id]);
    }

    public function test_estacion_user_cannot_update_a_past_dated_comisionado_fuera_of_their_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->subDays(3),
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.comisionados.update', $comisionado), [
            'empleado_id' => $empleado->id,
            'motivo' => 'Intento',
        ]);

        $response->assertForbidden();
    }

    // --- Authorization: Jefe de Zona (read-only) ----------------------------

    public function test_zone_chief_cannot_create_comisionado_fuera(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->post(
            route('asistencia.captura.comisionados.store'),
            $this->storePayload($empleado->id, ['estacion' => $estacion->id]),
        );

        $response->assertForbidden();
        $this->assertDatabaseCount('comisionados_fuera', 0);
    }

    // --- Authorization: Administrador ---------------------------------------

    public function test_administrador_can_create_comisionado_fuera_for_any_station_and_any_fecha(): void
    {
        $edificioZonalEste = $this->estacionOperativa(['is_operativa' => false, 'nombre' => 'Edificio Zonal Este']);
        $empleado = $this->empleadoActivo($edificioZonalEste);
        $this->actingAsTwoFactorVerified($this->administrador());

        $pastDate = CarbonImmutable::today()->subDays(10);

        $response = $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id, [
            'estacion' => $edificioZonalEste->id,
            'fecha' => $pastDate->toDateString(),
        ]));

        $response->assertRedirect();
        $this->assertDatabaseHas('comisionados_fuera', [
            'empleado_id' => $empleado->id,
            'estacion_id' => $edificioZonalEste->id,
        ]);
    }

    public function test_administrador_can_update_a_past_dated_comisionado_fuera(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->subDays(10),
        ]);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('asistencia.captura.comisionados.update', $comisionado), [
            'empleado_id' => $empleado->id,
            'motivo' => 'Corregido',
        ]);

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('comisionados_fuera', ['id' => $comisionado->id, 'motivo' => 'Corregido']);
    }

    // --- Validation ----------------------------------------------------------

    public function test_store_requires_a_valid_empleado_id(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload(999999));

        $response->assertInvalid(['empleado_id']);
    }

    public function test_store_rejects_a_fecha_fin_before_fecha_inicio(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id, [
            'fecha_inicio' => CarbonImmutable::today()->toDateString(),
            'fecha_fin' => CarbonImmutable::today()->subDay()->toDateString(),
        ]));

        $response->assertInvalid(['fecha_fin']);
    }

    // --- Mass-assignment safety ----------------------------------------------

    public function test_store_ignores_client_supplied_registrado_por_and_estacion_id(): void
    {
        $estacion = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $intruder = User::factory()->create();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id, [
            'registrado_por' => $intruder->id,
            'estacion_id' => $otherStation->id,
        ]));

        $comisionado = ComisionadoFuera::firstOrFail();
        $this->assertSame($actor->id, $comisionado->registrado_por);
        $this->assertSame($estacion->id, $comisionado->estacion_id);
    }

    // --- Audit logging ---------------------------------------------------------

    public function test_creating_a_comisionado_fuera_logs_a_created_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id));

        $comisionado = ComisionadoFuera::firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $comisionado->id)
            ->where('subject_type', ComisionadoFuera::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_updating_a_comisionado_fuera_logs_an_updated_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));
        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);

        $this->put(route('asistencia.captura.comisionados.update', $comisionado), [
            'empleado_id' => $empleado->id,
            'motivo' => 'Otro motivo',
        ]);

        $activity = Activity::query()
            ->where('subject_id', $comisionado->id)
            ->where('subject_type', ComisionadoFuera::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_deleting_a_comisionado_fuera_logs_a_deleted_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));
        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
        ]);

        $this->delete(route('asistencia.captura.comisionados.destroy', $comisionado));

        $activity = Activity::query()
            ->where('subject_id', $comisionado->id)
            ->where('subject_type', ComisionadoFuera::class)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    // --- Happy path --------------------------------------------------------

    public function test_full_create_read_update_delete_cycle(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion, ['nombre_completo' => 'Empleado Comisionado De Prueba']);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->post(route('asistencia.captura.comisionados.store'), $this->storePayload($empleado->id))
            ->assertRedirect(route('asistencia.captura.index'));

        $indexResponse = $this->get(route('asistencia.captura.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Empleado Comisionado De Prueba');

        $comisionado = ComisionadoFuera::firstOrFail();

        $this->put(route('asistencia.captura.comisionados.update', $comisionado), [
            'empleado_id' => $empleado->id,
            'motivo' => 'Actualizado Correctamente',
        ])->assertRedirect(route('asistencia.captura.index'));

        $this->assertDatabaseHas('comisionados_fuera', ['id' => $comisionado->id, 'motivo' => 'Actualizado Correctamente']);

        $this->delete(route('asistencia.captura.comisionados.destroy', $comisionado))
            ->assertRedirect(route('asistencia.captura.index'));

        $this->assertDatabaseMissing('comisionados_fuera', ['id' => $comisionado->id]);
    }

    // --- Regression: coexistence with RegistroDiario (Vacaciones) ----------

    /**
     * Confirmed against the real reference "oficio" document: an employee
     * can have an active RegistroDiario status (e.g. Vacaciones with a
     * date range) AND a ComisionadoFuera entry for the SAME day at the
     * same time. This is exactly why comisionados_fuera has no
     * unique(empleado_id, fecha) constraint — this test guards that.
     */
    public function test_registro_diario_vacaciones_and_comisionado_fuera_coexist_for_the_same_empleado_and_fecha(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $today = CarbonImmutable::today();

        $registro = RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $today,
            'estatus' => EstatusAsistencia::Vacaciones->value,
            'fecha_inicio' => $today,
            'fecha_fin' => $today->addDays(5),
        ]);

        $comisionado = ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $today,
            'coordinacion_destino' => 'CGOFP',
        ]);

        $this->assertDatabaseHas('registros_diarios', [
            'id' => $registro->id,
            'empleado_id' => $empleado->id,
            'estatus' => EstatusAsistencia::Vacaciones->value,
        ]);
        $this->assertDatabaseHas('comisionados_fuera', [
            'id' => $comisionado->id,
            'empleado_id' => $empleado->id,
            'coordinacion_destino' => 'CGOFP',
        ]);
        $this->assertSame(
            $today->toDateString(),
            RegistroDiario::whereKey($registro->id)->firstOrFail()->fecha->toDateString(),
        );
        $this->assertSame(
            $today->toDateString(),
            ComisionadoFuera::whereKey($comisionado->id)->firstOrFail()->fecha->toDateString(),
        );
    }

    public function test_two_comisionado_fuera_entries_can_coexist_for_the_same_empleado_and_fecha(): void
    {
        // Locks in the deliberate absence of unique(empleado_id, fecha) on
        // comisionados_fuera (see the migration's docblock) — e.g. a
        // correction/second commission note for the same day is legitimate,
        // unlike registros_diarios which has exactly one row per day.
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $today = CarbonImmutable::today();

        ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $today,
            'coordinacion_destino' => 'CGOFP',
        ]);
        ComisionadoFuera::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $today,
            'coordinacion_destino' => 'CGGIF',
        ]);

        $this->assertSame(
            2,
            ComisionadoFuera::where('empleado_id', $empleado->id)->whereDate('fecha', $today)->count(),
        );
    }
}
