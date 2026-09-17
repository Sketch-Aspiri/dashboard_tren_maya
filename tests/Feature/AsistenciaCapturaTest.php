<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
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
 * Feature coverage for the daily attendance capture screen (Fase 1 —
 * Control de Asistencia Diaria, see the approved plan). Exercises the
 * checklist required by .claude/rules/testing.md: authentication,
 * authorization per role (including station scoping), validation,
 * mass-assignment safety, audit logging, and the happy path. Uses only
 * dummy factory data.
 */
class AsistenciaCapturaTest extends TestCase
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

    // --- Authentication --------------------------------------------------

    public function test_guest_is_redirected_from_captura_index(): void
    {
        $response = $this->get(route('asistencia.captura.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_captura_update(): void
    {
        $response = $this->put(route('asistencia.captura.update'), []);

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked_from_captura_index(): void
    {
        $estacion = $this->estacionOperativa();
        $user = $this->estacionUser($estacion);

        $response = $this->actingAs($user)->get(route('asistencia.captura.index'));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization: Estación role ------------------------------------

    public function test_estacion_user_can_view_own_capture_screen(): void
    {
        $estacion = $this->estacionOperativa();
        $this->empleadoActivo($estacion, ['nombre_completo' => 'Empleado De Prueba']);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.captura.index'));

        $response->assertOk();
        $response->assertSee('Empleado De Prueba');
    }

    public function test_estacion_user_can_save_own_capture_screen(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => [
                    'empleado_id' => $empleado->id,
                    'estatus' => EstatusAsistencia::Presente->value,
                ],
            ],
        ]);

        $response->assertRedirect(route('asistencia.captura.index'));
        $this->assertDatabaseHas('registros_diarios', [
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'estatus' => EstatusAsistencia::Presente->value,
        ]);
    }

    public function test_estacion_user_querystring_estacion_override_is_ignored_not_honored(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $this->empleadoActivo($ownStation, ['nombre_completo' => 'Personal De Mi Estacion']);
        $this->empleadoActivo($otherStation, ['nombre_completo' => 'Personal De Otra Estacion']);
        $this->actingAsTwoFactorVerified($this->estacionUser($ownStation));

        $response = $this->get(route('asistencia.captura.index', ['estacion' => $otherStation->id]));

        $response->assertOk();
        $response->assertSee('Personal De Mi Estacion');
        $response->assertDontSee('Personal De Otra Estacion');
    }

    public function test_estacion_user_querystring_fecha_override_is_ignored_not_honored(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today()->subDays(5),
            'estatus' => EstatusAsistencia::Vacaciones->value,
        ]);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.captura.index', [
            'fecha' => CarbonImmutable::today()->subDays(5)->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('fecha', fn (CarbonImmutable $fecha) => $fecha->isToday());
    }

    public function test_capture_for_ability_denies_an_estacion_user_a_different_station(): void
    {
        $ownStation = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $user = $this->estacionUser($ownStation);

        $this->assertFalse($user->can('captureFor', [RegistroDiario::class, $otherStation]));
        $this->assertTrue($user->can('captureFor', [RegistroDiario::class, $ownStation]));
    }

    // --- Authorization: Jefe de Zona (read-only, no capture access) ------

    public function test_zone_chief_cannot_view_captura_index(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('asistencia.captura.index', ['estacion' => $estacion->id]));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_save_captura(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->put(route('asistencia.captura.update', ['estacion' => $estacion->id]), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('registros_diarios', 0);
    }

    // --- Authorization: Administrador -------------------------------------

    public function test_administrador_can_capture_any_operational_station(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('asistencia.captura.update', ['estacion' => $estacion->id]), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $response->assertRedirect(route('asistencia.captura.index', [
            'estacion' => $estacion->id,
            'fecha' => CarbonImmutable::today()->toDateString(),
        ]));
        $this->assertDatabaseHas('registros_diarios', ['empleado_id' => $empleado->id, 'estacion_id' => $estacion->id]);
    }

    public function test_administrador_can_capture_edificio_zonal_este_non_operational_station(): void
    {
        $edificioZonalEste = $this->estacionOperativa(['is_operativa' => false, 'nombre' => 'Edificio Zonal Este']);
        $empleado = $this->empleadoActivo($edificioZonalEste);
        $this->actingAsTwoFactorVerified($this->administrador());

        $getResponse = $this->get(route('asistencia.captura.index', ['estacion' => $edificioZonalEste->id]));
        $getResponse->assertOk();

        $putResponse = $this->put(route('asistencia.captura.update', ['estacion' => $edificioZonalEste->id]), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $putResponse->assertRedirect(route('asistencia.captura.index', [
            'estacion' => $edificioZonalEste->id,
            'fecha' => CarbonImmutable::today()->toDateString(),
        ]));
        $this->assertDatabaseHas('registros_diarios', ['empleado_id' => $empleado->id, 'estacion_id' => $edificioZonalEste->id]);
    }

    public function test_administrador_can_save_a_past_date_correction(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $pastDate = CarbonImmutable::today()->subDays(5);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->put(route('asistencia.captura.update', ['estacion' => $estacion->id]), [
            'fecha' => $pastDate->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Vacaciones->value],
            ],
        ]);

        $response->assertRedirect(route('asistencia.captura.index', [
            'estacion' => $estacion->id,
            'fecha' => $pastDate->toDateString(),
        ]));

        $registro = RegistroDiario::where('empleado_id', $empleado->id)->firstOrFail();
        $this->assertSame($pastDate->toDateString(), $registro->fecha->toDateString());
        $this->assertSame(EstatusAsistencia::Vacaciones, $registro->estatus);
    }

    public function test_administrador_index_loads_the_requested_fecha_not_today(): void
    {
        // Regression: index() used to hardcode "today" regardless of
        // ?fecha=, so an Administrador correcting a past day would see
        // (and could unknowingly overwrite) today's data instead.
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $pastDate = CarbonImmutable::today()->subDays(5);

        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $pastDate,
            'estatus' => EstatusAsistencia::Vacaciones->value,
        ]);
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('asistencia.captura.index', [
            'estacion' => $estacion->id,
            'fecha' => $pastDate->toDateString(),
        ]));

        $response->assertOk();
        $response->assertViewHas('fecha', fn (CarbonImmutable $fecha) => $fecha->isSameDay($pastDate));
        $response->assertViewHas('roster', function ($roster) use ($empleado) {
            $registro = $roster->firstWhere('id', $empleado->id)?->registrosDiarios->first();

            return $registro !== null && $registro->estatus === EstatusAsistencia::Vacaciones;
        });
    }

    public function test_estacion_user_cannot_save_a_past_date_for_their_own_station(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $pastDate = CarbonImmutable::today()->subDays(5);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => $pastDate->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('registros_diarios', 0);
    }

    // --- Validation --------------------------------------------------------

    public function test_update_requires_estatus_for_each_row(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id],
            ],
        ]);

        $response->assertInvalid(["registros.{$empleado->id}.estatus"]);
    }

    public function test_update_rejects_an_estatus_outside_the_enum(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => 'not-a-real-status'],
            ],
        ]);

        $response->assertInvalid(["registros.{$empleado->id}.estatus"]);
    }

    public function test_update_rejects_a_fecha_fin_before_fecha_inicio(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => [
                    'empleado_id' => $empleado->id,
                    'estatus' => EstatusAsistencia::Vacaciones->value,
                    'fecha_inicio' => CarbonImmutable::today()->toDateString(),
                    'fecha_fin' => CarbonImmutable::today()->subDay()->toDateString(),
                ],
            ],
        ]);

        $response->assertInvalid(["registros.{$empleado->id}.fecha_fin"]);
    }

    public function test_update_rejects_an_empleado_from_a_different_station(): void
    {
        $estacion = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $otherEmpleado = $this->empleadoActivo($otherStation);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $otherEmpleado->id => ['empleado_id' => $otherEmpleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $response->assertInvalid(["registros.{$otherEmpleado->id}.empleado_id"]);
        $this->assertDatabaseCount('registros_diarios', 0);
    }

    // --- Mass-assignment safety --------------------------------------------

    public function test_update_ignores_client_supplied_estacion_id_and_registrado_por(): void
    {
        $estacion = $this->estacionOperativa();
        $otherStation = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $intruder = User::factory()->create();
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => [
                    'empleado_id' => $empleado->id,
                    'estatus' => EstatusAsistencia::Presente->value,
                    'estacion_id' => $otherStation->id,
                    'registrado_por' => $intruder->id,
                ],
            ],
        ]);

        $registro = RegistroDiario::where('empleado_id', $empleado->id)->firstOrFail();

        $this->assertSame($estacion->id, $registro->estacion_id);
        $this->assertSame($actor->id, $registro->registrado_por);
    }

    // --- Audit logging -------------------------------------------------------

    public function test_saving_captura_logs_a_created_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => EstatusAsistencia::Presente->value],
            ],
        ]);

        $registro = RegistroDiario::where('empleado_id', $empleado->id)->firstOrFail();

        $activity = Activity::query()->where('subject_id', $registro->id)
            ->where('subject_type', RegistroDiario::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_saving_captura_a_second_time_logs_an_updated_activity(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $actor = $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $payload = fn (string $estatus) => [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => $estatus],
            ],
        ];

        $this->put(route('asistencia.captura.update'), $payload(EstatusAsistencia::Presente->value));
        $this->put(route('asistencia.captura.update'), $payload(EstatusAsistencia::Vacaciones->value));

        $registro = RegistroDiario::where('empleado_id', $empleado->id)->firstOrFail();

        $activity = Activity::query()->where('subject_id', $registro->id)
            ->where('subject_type', RegistroDiario::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    // --- Happy path ----------------------------------------------------------

    public function test_roster_shows_no_status_preselected_for_everyone_without_an_existing_row(): void
    {
        // The estatus <select> must not silently default to "Presente" —
        // it starts blank so the estación has to explicitly pick a status
        // for every person before saving.
        $estacion = $this->estacionOperativa();
        $this->empleadoActivo($estacion, ['nombre_completo' => 'Sin Registro Aun']);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.captura.index'));

        $response->assertOk();
        $response->assertSee('Sin Registro Aun');
        $response->assertSee('-- Selecciona --');
    }

    public function test_leaving_a_status_blank_is_rejected_not_silently_saved_as_presente(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => ''],
            ],
        ]);

        $response->assertInvalid(['registros.'.$empleado->id.'.estatus']);
        $this->assertDatabaseCount('registros_diarios', 0);
    }

    public function test_submitting_a_status_change_with_date_range_persists_and_reflects_on_reload(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion, ['nombre_completo' => 'Persona De Vacaciones']);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $inicio = CarbonImmutable::today();
        $fin = CarbonImmutable::today()->addDays(6);

        $this->put(route('asistencia.captura.update'), [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => [
                    'empleado_id' => $empleado->id,
                    'estatus' => EstatusAsistencia::Vacaciones->value,
                    'fecha_inicio' => $inicio->toDateString(),
                    'fecha_fin' => $fin->toDateString(),
                    'notas' => 'Vacaciones anuales',
                ],
            ],
        ])->assertRedirect(route('asistencia.captura.index'));

        $registro = RegistroDiario::where('empleado_id', $empleado->id)->firstOrFail();
        $this->assertSame(EstatusAsistencia::Vacaciones, $registro->estatus);
        $this->assertSame($inicio->toDateString(), $registro->fecha_inicio->toDateString());
        $this->assertSame($fin->toDateString(), $registro->fecha_fin->toDateString());
        $this->assertSame('Vacaciones anuales', $registro->notas);

        $reload = $this->get(route('asistencia.captura.index'));
        $reload->assertOk();
        $reload->assertSee('Persona De Vacaciones');
    }

    public function test_resubmitting_the_same_day_updates_in_place_instead_of_duplicating(): void
    {
        $estacion = $this->estacionOperativa();
        $empleado = $this->empleadoActivo($estacion);
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $payload = fn (string $estatus) => [
            'fecha' => CarbonImmutable::today()->toDateString(),
            'registros' => [
                $empleado->id => ['empleado_id' => $empleado->id, 'estatus' => $estatus],
            ],
        ];

        $this->put(route('asistencia.captura.update'), $payload(EstatusAsistencia::Presente->value));
        $this->assertDatabaseCount('registros_diarios', 1);

        $this->put(route('asistencia.captura.update'), $payload(EstatusAsistencia::Descanso->value));
        $this->assertDatabaseCount('registros_diarios', 1);
        $this->assertDatabaseHas('registros_diarios', [
            'empleado_id' => $empleado->id,
            'estatus' => EstatusAsistencia::Descanso->value,
        ]);
    }
}
