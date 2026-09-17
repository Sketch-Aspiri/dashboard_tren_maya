<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Gestión de usuarios" (Administrador-only account
 * management panel). Exercises the checklist required by
 * .claude/rules/testing.md: authorization per role, authentication,
 * validation, mass-assignment safety, audit logging, and the full
 * happy-path CRUD cycle — plus the two security-critical rules unique to
 * this module: passwords must never appear in the audit log, and an
 * Administrador can never delete their own account.
 */
class UserManagementCrudTest extends TestCase
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

    private function estacionAccount(): User
    {
        $user = User::factory()->create(['estacion_id' => Estacion::factory()->create()->id]);
        $user->assignRole('Estación');

        return $user;
    }

    private function operativaEstacion(): Estacion
    {
        return Estacion::factory()->create(['is_operativa' => true]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Cuenta de Prueba',
            'email' => 'cuenta.prueba@example.com',
            'role' => 'Administrador',
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ], $overrides);
    }

    // --- Authentication ------------------------------------------------

    public function test_guest_is_redirected_from_usuarios_index(): void
    {
        $response = $this->get(route('usuarios.index'));

        $response->assertRedirect(route('login'));
    }

    // --- Authorization ---------------------------------------------------

    public function test_administrador_can_view_usuarios_index(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('usuarios.index'));

        $response->assertOk();
    }

    public function test_administrador_can_view_create_form(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('usuarios.create'));

        $response->assertOk();
    }

    public function test_zone_chief_cannot_view_usuarios_index(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $this->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_zone_chief_cannot_view_create_form(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $this->get(route('usuarios.create'))->assertForbidden();
    }

    public function test_zone_chief_cannot_store_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->post(route('usuarios.store'), $this->validPayload());

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'cuenta.prueba@example.com']);
    }

    public function test_zone_chief_cannot_view_edit_form(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $target = $this->administrador();

        $this->get(route('usuarios.edit', $target))->assertForbidden();
    }

    public function test_zone_chief_cannot_update_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $target = $this->administrador();

        $response = $this->put(route('usuarios.update', $target), $this->validPayload(['email' => $target->email]));

        $response->assertForbidden();
    }

    public function test_zone_chief_cannot_delete_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());
        $target = $this->administrador();

        $response = $this->delete(route('usuarios.destroy', $target));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    public function test_estacion_role_cannot_view_usuarios_index(): void
    {
        $this->actingAsTwoFactorVerified($this->estacionAccount());

        $this->get(route('usuarios.index'))->assertForbidden();
    }

    public function test_estacion_role_cannot_store_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->estacionAccount());

        $response = $this->post(route('usuarios.store'), $this->validPayload());

        $response->assertForbidden();
    }

    public function test_estacion_role_cannot_update_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->estacionAccount());
        $target = $this->administrador();

        $response = $this->put(route('usuarios.update', $target), $this->validPayload(['email' => $target->email]));

        $response->assertForbidden();
    }

    public function test_estacion_role_cannot_delete_a_user(): void
    {
        $this->actingAsTwoFactorVerified($this->estacionAccount());
        $target = $this->administrador();

        $response = $this->delete(route('usuarios.destroy', $target));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }

    // --- Validation ------------------------------------------------------

    public function test_store_requires_name_email_role_and_password(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('usuarios.store'), []);

        $response->assertInvalid(['name', 'email', 'role', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_store_rejects_a_duplicate_email(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $existing = User::factory()->create();

        $response = $this->post(route('usuarios.store'), $this->validPayload(['email' => $existing->email]));

        $response->assertInvalid(['email']);
    }

    public function test_store_rejects_a_role_outside_the_seeded_list(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('usuarios.store'), $this->validPayload(['role' => 'Superadmin']));

        $response->assertInvalid(['role']);
    }

    public function test_store_requires_estacion_id_when_role_is_estacion(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('usuarios.store'), $this->validPayload(['role' => 'Estación']));

        $response->assertInvalid(['estacion_id']);
    }

    public function test_store_rejects_a_non_operational_estacion(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $noOperativa = Estacion::factory()->create(['is_operativa' => false]);

        $response = $this->post(route('usuarios.store'), $this->validPayload([
            'role' => 'Estación',
            'estacion_id' => $noOperativa->id,
        ]));

        $response->assertInvalid(['estacion_id']);
    }

    public function test_store_accepts_an_operational_estacion_for_role_estacion(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $estacion = $this->operativaEstacion();

        $response = $this->post(route('usuarios.store'), $this->validPayload([
            'role' => 'Estación',
            'estacion_id' => $estacion->id,
        ]));

        $response->assertValid();
        $this->assertDatabaseHas('users', ['email' => 'cuenta.prueba@example.com', 'estacion_id' => $estacion->id]);
    }

    public function test_store_ignores_estacion_id_when_role_is_not_estacion(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $estacion = $this->operativaEstacion();

        $this->post(route('usuarios.store'), $this->validPayload([
            'role' => 'Administrador',
            'estacion_id' => $estacion->id,
        ]))->assertValid();

        $this->assertDatabaseHas('users', ['email' => 'cuenta.prueba@example.com', 'estacion_id' => null]);
    }

    public function test_store_requires_password_confirmation_to_match(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('usuarios.store'), $this->validPayload([
            'password_confirmation' => 'algo-distinto',
        ]));

        $response->assertInvalid(['password']);
    }

    public function test_store_rejects_a_weak_password(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->post(route('usuarios.store'), $this->validPayload([
            'password' => '123',
            'password_confirmation' => '123',
        ]));

        $response->assertInvalid(['password']);
    }

    public function test_update_allows_blank_password_to_keep_it_unchanged(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->administrador();

        $response = $this->put(route('usuarios.update', $target), $this->validPayload([
            'email' => $target->email,
            'password' => '',
            'password_confirmation' => '',
        ]));

        $response->assertValid();
    }

    public function test_update_validates_password_strength_when_provided(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->administrador();

        $response = $this->put(route('usuarios.update', $target), $this->validPayload([
            'email' => $target->email,
            'password' => '123',
            'password_confirmation' => '123',
        ]));

        $response->assertInvalid(['password']);
    }

    // --- Mass-assignment safety ---------------------------------------

    public function test_store_ignores_attributes_outside_fillable(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('usuarios.store'), $this->validPayload([
            'id' => 999999,
            'created_at' => '1999-01-01 00:00:00',
        ]));

        $user = User::query()->where('email', 'cuenta.prueba@example.com')->firstOrFail();

        $this->assertNotSame(999999, $user->id);
        $this->assertNotSame('1999-01-01 00:00:00', $user->created_at->format('Y-m-d H:i:s'));
    }

    // --- Self-deletion is blocked ---------------------------------------

    public function test_administrador_cannot_delete_their_own_account(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->delete(route('usuarios.destroy', $admin));

        $response->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_administrador_can_delete_a_different_administrador_account(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->administrador();

        $response = $this->delete(route('usuarios.destroy', $target));

        $response->assertRedirect(route('usuarios.index'));
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    // --- Audit logging ---------------------------------------------------

    public function test_creating_a_user_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('usuarios.store'), $this->validPayload());

        $user = User::query()->where('email', 'cuenta.prueba@example.com')->firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_updating_a_user_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->administrador();

        $this->put(route('usuarios.update', $target), $this->validPayload([
            'email' => $target->email,
            'name' => 'Nombre actualizado',
            'password' => '',
            'password_confirmation' => '',
        ]));

        $activity = Activity::query()
            ->where('subject_id', $target->id)
            ->where('subject_type', User::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_deleting_a_user_is_logged(): void
    {
        $admin = $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->administrador();
        $targetId = $target->id;

        $this->delete(route('usuarios.destroy', $target));

        $activity = Activity::query()
            ->where('subject_id', $targetId)
            ->where('subject_type', User::class)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($admin->id, $activity->causer_id);
    }

    public function test_role_assignment_on_create_is_logged(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('usuarios.store'), $this->validPayload(['role' => 'Jefe de Zona']));

        $user = User::query()->where('email', 'cuenta.prueba@example.com')->firstOrFail();

        $activity = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->where('event', 'role_assigned')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(['Jefe de Zona'], $activity->properties->get('attributes')['roles']);
    }

    public function test_role_change_on_update_is_logged(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $target = $this->zoneChief();

        $this->put(route('usuarios.update', $target), $this->validPayload([
            'email' => $target->email,
            'role' => 'Administrador',
            'password' => '',
            'password_confirmation' => '',
        ]));

        $activity = Activity::query()
            ->where('subject_id', $target->id)
            ->where('subject_type', User::class)
            ->where('event', 'role_assigned')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame(['Jefe de Zona'], $activity->properties->get('old')['roles']);
        $this->assertSame(['Administrador'], $activity->properties->get('attributes')['roles']);
    }

    /**
     * The single most important security-regression test in this module:
     * no Activity row for this user — created, updated, or the manual
     * role_assigned log — may ever contain the plaintext or hashed
     * password value, under any properties key.
     */
    public function test_activity_log_never_contains_the_password_value(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $this->post(route('usuarios.store'), $this->validPayload([
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ]));

        $user = User::query()->where('email', 'cuenta.prueba@example.com')->firstOrFail();

        $this->put(route('usuarios.update', $user), $this->validPayload([
            'email' => $user->email,
            'role' => 'Jefe de Zona',
            'password' => 'OtraContraseñaSegura456!',
            'password_confirmation' => 'OtraContraseñaSegura456!',
        ]));

        $activities = Activity::query()
            ->where('subject_id', $user->id)
            ->where('subject_type', User::class)
            ->get();

        $this->assertNotEmpty($activities);

        $forbiddenValues = ['ContraseñaSegura123!', 'OtraContraseñaSegura456!', $user->fresh()->password];

        foreach ($activities as $activity) {
            $serialized = json_encode($activity->properties);

            $this->assertIsString($serialized);

            foreach ($forbiddenValues as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $serialized);
            }

            // password must never even appear as a logged key with a
            // real (non-redacted) value.
            foreach (['attributes', 'old'] as $key) {
                $values = $activity->properties->get($key);

                if (is_array($values) && array_key_exists('password', $values)) {
                    $this->assertSame('[redactado]', $values['password']);
                }
            }
        }
    }

    // --- Happy path --------------------------------------------------------

    public function test_administrador_can_complete_the_full_estacion_account_lifecycle(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());
        $estacion = $this->operativaEstacion();

        // Create
        $this->post(route('usuarios.store'), $this->validPayload([
            'name' => 'Cuenta Estación',
            'email' => 'estacion.lifecycle@example.com',
            'role' => 'Estación',
            'estacion_id' => $estacion->id,
            'password' => 'ContraseñaInicial123!',
            'password_confirmation' => 'ContraseñaInicial123!',
        ]))->assertRedirect(route('usuarios.index'));

        $user = User::query()->where('email', 'estacion.lifecycle@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Estación'));
        $this->assertSame($estacion->id, $user->estacion_id);
        $this->assertTrue(Hash::check('ContraseñaInicial123!', $user->password));

        // Edit: change name, leave password blank -> unchanged
        $this->put(route('usuarios.update', $user), [
            'name' => 'Cuenta Estación (renombrada)',
            'email' => $user->email,
            'role' => 'Estación',
            'estacion_id' => $estacion->id,
            'password' => '',
            'password_confirmation' => '',
        ])->assertRedirect(route('usuarios.index'));

        $user->refresh();
        $this->assertSame('Cuenta Estación (renombrada)', $user->name);
        $this->assertTrue(Hash::check('ContraseñaInicial123!', $user->password));

        // Change password
        $this->put(route('usuarios.update', $user), [
            'name' => $user->name,
            'email' => $user->email,
            'role' => 'Estación',
            'estacion_id' => $estacion->id,
            'password' => 'ContraseñaNueva456!',
            'password_confirmation' => 'ContraseñaNueva456!',
        ])->assertRedirect(route('usuarios.index'));

        $user->refresh();
        $this->assertFalse(Hash::check('ContraseñaInicial123!', $user->password));
        $this->assertTrue(Hash::check('ContraseñaNueva456!', $user->password));

        // Delete (as a different Administrador, since self-deletion is blocked)
        $this->delete(route('usuarios.destroy', $user))
            ->assertRedirect(route('usuarios.index'));

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}
