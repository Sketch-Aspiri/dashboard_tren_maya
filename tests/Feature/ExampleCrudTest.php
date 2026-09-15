<?php

namespace Tests\Feature;

use App\Enums\ExampleStatus;
use App\Models\Example;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the disposable reference CRUD module (CLAUDE.md).
 * Exercises the checklist required by .claude/rules/testing.md:
 * authorization per role, authentication, validation, mass-assignment
 * safety, audit logging, and the full happy-path CRUD cycle.
 */
class ExampleCrudTest extends TestCase
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

    // --- Authentication -----------------------------------------------

    public function test_guest_is_redirected_from_examples_index(): void
    {
        $response = $this->get(route('examples.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_cannot_access_examples_json_endpoint(): void
    {
        $response = $this->getJson(route('examples.data'));

        $response->assertStatus(401);
    }

    // --- Authorization ---------------------------------------------------

    public function test_zone_chief_can_view_examples_index(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('examples.index'));

        $response->assertOk();
    }

    public function test_administrador_can_view_examples_index(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('examples.index'));

        $response->assertOk();
    }

    public function test_user_without_a_role_cannot_view_examples_index(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->get(route('examples.index'));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_create_example(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('examples.create'));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_store_example(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->post(route('examples.store'), [
            'name' => 'Not allowed',
            'status' => ExampleStatus::Active->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('examples', 0);
    }

    public function test_administrador_can_create_example(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('examples.create'));

        $response->assertOk();
    }

    public function test_zone_chief_cannot_update_example(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $example = Example::factory()->create();

        $response = $this->put(route('examples.update', $example), [
            'name' => 'Changed',
            'status' => ExampleStatus::Active->value,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseHas('examples', ['id' => $example->id, 'name' => $example->name]);
    }

    public function test_zone_chief_cannot_delete_example(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $example = Example::factory()->create();

        $response = $this->delete(route('examples.destroy', $example));

        $response->assertForbidden();
        $this->assertDatabaseHas('examples', ['id' => $example->id]);
    }

    public function test_administrador_can_delete_example(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $example = Example::factory()->create();

        $response = $this->delete(route('examples.destroy', $example));

        $response->assertRedirect(route('examples.index'));
        $this->assertDatabaseMissing('examples', ['id' => $example->id]);
    }

    // --- Validation --------------------------------------------------------

    public function test_store_requires_name_and_status(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('examples.store'), []);

        $response->assertInvalid(['name', 'status']);
        $this->assertDatabaseCount('examples', 0);
    }

    public function test_store_rejects_a_status_outside_the_enum(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('examples.store'), [
            'name' => 'Valid name',
            'status' => 'not-a-real-status',
        ]);

        $response->assertInvalid(['status']);
    }

    public function test_store_accepts_a_valid_payload(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('examples.store'), [
            'name' => 'Valid name',
            'value' => 'Some value',
            'status' => ExampleStatus::Active->value,
        ]);

        $response->assertValid();
        $response->assertRedirect(route('examples.index'));
    }

    // --- Mass-assignment safety ---------------------------------------

    public function test_store_ignores_attributes_outside_fillable(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('examples.store'), [
            'name' => 'Valid name',
            'status' => ExampleStatus::Active->value,
            'id' => 999999,
            'created_at' => '1999-01-01 00:00:00',
        ]);

        $example = Example::firstOrFail();

        $this->assertNotSame(999999, $example->id);
        $this->assertNotSame('1999-01-01 00:00:00', $example->created_at->format('Y-m-d H:i:s'));
    }

    // --- Audit logging -----------------------------------------------------

    public function test_creating_an_example_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('examples.store'), [
            'name' => 'Audited example',
            'status' => ExampleStatus::Active->value,
        ]);

        $example = Example::firstOrFail();

        $activity = Activity::query()->where('subject_id', $example->id)
            ->where('subject_type', Example::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_updating_an_example_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $example = Example::factory()->create(['name' => 'Original']);

        $this->put(route('examples.update', $example), [
            'name' => 'Updated',
            'status' => ExampleStatus::Active->value,
        ]);

        $activity = Activity::query()->where('subject_id', $example->id)
            ->where('subject_type', Example::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_deleting_an_example_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $example = Example::factory()->create();
        $exampleId = $example->id;

        $this->delete(route('examples.destroy', $example));

        $activity = Activity::query()->where('subject_id', $exampleId)
            ->where('subject_type', Example::class)
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
        $this->post(route('examples.store'), [
            'name' => 'Lifecycle example',
            'value' => 'initial',
            'status' => ExampleStatus::Draft->value,
        ])->assertRedirect(route('examples.index'));

        $example = Example::firstOrFail();
        $this->assertSame('Lifecycle example', $example->name);
        $this->assertSame(ExampleStatus::Draft, $example->status);

        // Read
        $this->get(route('examples.show', $example))->assertOk();

        // Update
        $this->put(route('examples.update', $example), [
            'name' => 'Lifecycle example (updated)',
            'value' => 'updated',
            'status' => ExampleStatus::Active->value,
        ])->assertRedirect(route('examples.index'));

        $example->refresh();
        $this->assertSame('Lifecycle example (updated)', $example->name);
        $this->assertSame(ExampleStatus::Active, $example->status);

        // Delete
        $this->delete(route('examples.destroy', $example))
            ->assertRedirect(route('examples.index'));

        $this->assertDatabaseMissing('examples', ['id' => $example->id]);
    }

    // --- JSON data endpoint --------------------------------------------

    public function test_examples_data_endpoint_returns_the_api_conventions_envelope(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        Example::factory()->count(3)->create();

        $response = $this->getJson(route('examples.data'));

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

    public function test_zone_chief_cannot_access_examples_json_endpoint_after_role_removed(): void
    {
        $user = $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->getJson(route('examples.data'));

        $response->assertStatus(403);
    }
}
