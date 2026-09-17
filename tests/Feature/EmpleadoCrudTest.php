<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the "Agenda Zona Oriente" -> Personal directory
 * (real data module, see CLAUDE.md). Exercises the checklist required by
 * .claude/rules/testing.md: authorization per role, authentication,
 * validation, mass-assignment safety, audit logging, and the full
 * happy-path CRUD cycle. Uses only dummy factory data — never real
 * employee PII.
 */
class EmpleadoCrudTest extends TestCase
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

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'no_empleado' => 'EMP-00123',
            'estatus' => EmpleadoEstatus::Activo->value,
            'nombre_completo' => 'Persona de Prueba',
            'puesto' => 'Auxiliar de prueba',
            'estacion_codigo' => 'EST-01',
        ], $overrides);
    }

    // --- Authentication -----------------------------------------------

    public function test_guest_is_redirected_from_agenda_personal_index(): void
    {
        $response = $this->get(route('agenda.personal.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_agenda_personal_json_endpoint(): void
    {
        $response = $this->getJson(route('agenda.personal.data'));

        $response->assertStatus(401);
    }

    // --- Authorization ---------------------------------------------------

    public function test_zone_chief_can_view_agenda_personal_index(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('agenda.personal.index'));

        $response->assertOk();
    }

    public function test_administrador_can_view_agenda_personal_index(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('agenda.personal.index'));

        $response->assertOk();
    }

    public function test_user_without_a_role_cannot_view_agenda_personal_index(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->get(route('agenda.personal.index'));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_create_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('agenda.personal.create'));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_store_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->post(route('agenda.personal.store'), $this->validPayload());

        $response->assertForbidden();
        $this->assertDatabaseCount('empleados', 0);
    }

    public function test_administrador_can_create_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('agenda.personal.create'));

        $response->assertOk();
    }

    public function test_zone_chief_cannot_update_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $empleado = Empleado::factory()->create();

        $response = $this->put(route('agenda.personal.update', $empleado), $this->validPayload([
            'no_empleado' => $empleado->no_empleado,
            'nombre_completo' => 'Cambiado',
        ]));

        $response->assertForbidden();
        $this->assertDatabaseHas('empleados', ['id' => $empleado->id, 'nombre_completo' => $empleado->nombre_completo]);
    }

    public function test_zone_chief_cannot_delete_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $empleado = Empleado::factory()->create();

        $response = $this->delete(route('agenda.personal.destroy', $empleado));

        $response->assertForbidden();
        $this->assertDatabaseHas('empleados', ['id' => $empleado->id]);
    }

    public function test_administrador_can_delete_empleado(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $empleado = Empleado::factory()->create();

        $response = $this->delete(route('agenda.personal.destroy', $empleado));

        $response->assertRedirect(route('agenda.personal.index'));
        $this->assertDatabaseMissing('empleados', ['id' => $empleado->id]);
    }

    // --- Validation --------------------------------------------------------

    public function test_store_requires_estatus(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('agenda.personal.store'), []);

        $response->assertInvalid(['estatus']);
        $this->assertDatabaseCount('empleados', 0);
    }

    public function test_store_rejects_an_estatus_outside_the_enum(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('agenda.personal.store'), $this->validPayload([
            'estatus' => 'not-a-real-status',
        ]));

        $response->assertInvalid(['estatus']);
    }

    public function test_store_rejects_a_curp_over_eighteen_characters(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('agenda.personal.store'), $this->validPayload([
            'curp' => str_repeat('A', 19),
        ]));

        $response->assertInvalid(['curp']);
    }

    public function test_store_accepts_a_valid_payload(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('agenda.personal.store'), $this->validPayload());

        $response->assertValid();
        $response->assertRedirect(route('agenda.personal.index'));
    }

    // --- Mass-assignment safety ---------------------------------------

    public function test_store_ignores_attributes_outside_fillable(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('agenda.personal.store'), $this->validPayload([
            'id' => 999999,
            'created_at' => '1999-01-01 00:00:00',
        ]));

        $empleado = Empleado::firstOrFail();

        $this->assertNotSame(999999, $empleado->id);
        $this->assertNotSame('1999-01-01 00:00:00', $empleado->created_at->format('Y-m-d H:i:s'));
    }

    // --- Audit logging -----------------------------------------------------

    public function test_creating_an_empleado_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('agenda.personal.store'), $this->validPayload([
            'curp' => 'PELJ900520MDFRPN01',
        ]));

        $empleado = Empleado::firstOrFail();

        $activity = Activity::query()->where('subject_id', $empleado->id)
            ->where('subject_type', Empleado::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_activity_log_redacts_sensitive_field_values(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('agenda.personal.store'), $this->validPayload([
            'curp' => 'PELJ900520MDFRPN01',
        ]));

        $empleado = Empleado::firstOrFail();

        $activity = Activity::query()->where('subject_id', $empleado->id)
            ->where('subject_type', Empleado::class)
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame('[redactado]', $activity->properties->get('attributes')['curp']);
        $this->assertSame('Persona de Prueba', $activity->properties->get('attributes')['nombre_completo']);
    }

    public function test_updating_an_empleado_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $empleado = Empleado::factory()->create(['nombre_completo' => 'Original']);

        $this->put(route('agenda.personal.update', $empleado), $this->validPayload([
            'no_empleado' => $empleado->no_empleado,
            'nombre_completo' => 'Actualizado',
        ]));

        $activity = Activity::query()->where('subject_id', $empleado->id)
            ->where('subject_type', Empleado::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_deleting_an_empleado_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $empleado = Empleado::factory()->create();
        $empleadoId = $empleado->id;

        $this->delete(route('agenda.personal.destroy', $empleado));

        $activity = Activity::query()->where('subject_id', $empleadoId)
            ->where('subject_type', Empleado::class)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    // --- Happy path ----------------------------------------------------

    public function test_administrador_can_complete_the_full_crud_cycle(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        // Create
        $this->post(route('agenda.personal.store'), $this->validPayload([
            'no_empleado' => 'EMP-LIFECYCLE',
            'nombre_completo' => 'Ciclo de vida',
        ]))->assertRedirect(route('agenda.personal.index'));

        $empleado = Empleado::firstOrFail();
        $this->assertSame('Ciclo de vida', $empleado->nombre_completo);
        $this->assertSame(EmpleadoEstatus::Activo, $empleado->estatus);

        // Read
        $this->get(route('agenda.personal.show', $empleado))->assertOk();

        // Update
        $this->put(route('agenda.personal.update', $empleado), $this->validPayload([
            'no_empleado' => 'EMP-LIFECYCLE',
            'nombre_completo' => 'Ciclo de vida (actualizado)',
            'estatus' => EmpleadoEstatus::Vacante->value,
        ]))->assertRedirect(route('agenda.personal.index'));

        $empleado->refresh();
        $this->assertSame('Ciclo de vida (actualizado)', $empleado->nombre_completo);
        $this->assertSame(EmpleadoEstatus::Vacante, $empleado->estatus);

        // Delete
        $this->delete(route('agenda.personal.destroy', $empleado))
            ->assertRedirect(route('agenda.personal.index'));

        $this->assertDatabaseMissing('empleados', ['id' => $empleado->id]);
    }

    // --- JSON data endpoint --------------------------------------------

    public function test_agenda_personal_data_endpoint_returns_the_api_conventions_envelope(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Empleado::factory()->count(3)->create();

        $response = $this->getJson(route('agenda.personal.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
            'error',
            'meta' => ['total', 'page', 'per_page', 'last_page'],
        ]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 3);
    }

    public function test_user_without_role_cannot_access_agenda_personal_json_endpoint(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->getJson(route('agenda.personal.data'));

        $response->assertStatus(403);
    }

    public function test_agenda_personal_data_endpoint_filters_by_estatus(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Empleado::factory()->count(2)->create(['estatus' => EmpleadoEstatus::Activo->value]);
        Empleado::factory()->vacante()->create();

        $response = $this->getJson(route('agenda.personal.data', ['estatus' => EmpleadoEstatus::Vacante->value]));

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_agenda_personal_data_endpoint_does_not_expose_sensitive_fields(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Empleado::factory()->create();

        $response = $this->getJson(route('agenda.personal.data'));

        $response->assertOk();
        $response->assertJsonMissingPath('data.0.curp');
        $response->assertJsonMissingPath('data.0.rfc');
        $response->assertJsonMissingPath('data.0.nss');
        $response->assertJsonMissingPath('data.0.domicilio');
        $response->assertJsonMissingPath('data.0.alergias');
        $response->assertJsonMissingPath('data.0.contacto_emergencia_nombre');
    }

    public function test_agenda_personal_data_endpoint_paginates_past_the_first_page(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Empleado::factory()->count(20)->create();

        $firstPage = $this->getJson(route('agenda.personal.data'));
        $firstPage->assertOk();
        $firstPage->assertJsonPath('meta.total', 20);
        $firstPage->assertJsonPath('meta.last_page', 2);
        $firstPage->assertJsonCount(15, 'data');

        $secondPage = $this->getJson(route('agenda.personal.data', ['page' => 2]));
        $secondPage->assertOk();
        $secondPage->assertJsonPath('meta.page', 2);
        $secondPage->assertJsonCount(5, 'data');

        $firstPageIds = collect($firstPage->json('data'))->pluck('id');
        $secondPageIds = collect($secondPage->json('data'))->pluck('id');
        $this->assertEmpty($firstPageIds->intersect($secondPageIds));
    }

    public function test_agenda_personal_index_renders_pagination_metadata_for_the_alpine_table(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Empleado::factory()->count(20)->create();

        $response = $this->get(route('agenda.personal.index'));

        $response->assertOk();
        $response->assertSee('"total":20', false);
        $response->assertSee('"last_page":2', false);
    }

    public function test_agenda_personal_data_endpoint_defaults_to_excel_row_order_not_alphabetical(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        // Created out of alphabetical order on purpose: "Zeta" has the
        // smallest orden_origen (earliest Excel row) and must come first.
        $zeta = Empleado::factory()->create(['nombre_completo' => 'Zeta Empleado', 'orden_origen' => 2]);
        $alfa = Empleado::factory()->create(['nombre_completo' => 'Alfa Empleado', 'orden_origen' => 5]);
        $manualNoOrder = Empleado::factory()->create(['nombre_completo' => 'Manual Sin Orden', 'orden_origen' => null]);

        $response = $this->getJson(route('agenda.personal.data'));

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertSame([$zeta->id, $alfa->id, $manualNoOrder->id], $ids->all());
    }

    public function test_show_page_still_includes_full_record_for_an_authorized_reader(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $empleado = Empleado::factory()->create(['curp' => 'PELJ900520MDFRPN01']);

        $response = $this->get(route('agenda.personal.show', $empleado));

        $response->assertOk();
        $response->assertSee('PELJ900520MDFRPN01');
    }
}
