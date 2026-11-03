<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\User;
use App\Policies\UserPolicy;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Protege la cuenta de producción (Jefe de Zona + Administrador): editarla
 * desde el panel de Usuarios no debe quitarle ningún rol ni tocar su
 * contraseña, y el panel soporta cuentas con varios roles.
 */
class CuentaJefaturaRegresionTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    private function cuentaJefatura(): User
    {
        $user = User::factory()->create(['email' => 'jefe@example.com']);
        $user->assignRole(['Jefe de Zona', 'Administrador']);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'name' => $user->name,
            'email' => $user->email,
            'roles' => ['Jefe de Zona', 'Administrador'],
            'password' => '',
            'password_confirmation' => '',
        ], $overrides);
    }

    public function test_editing_account_with_jefe_and_admin_keeps_both_roles_and_password(): void
    {
        $cuenta = $this->actingAsTwoFactorVerified($this->cuentaJefatura());
        $hashAntes = $cuenta->password;

        $this->put(route('usuarios.update', $cuenta), $this->payload($cuenta, ['name' => 'Nombre Nuevo']))
            ->assertRedirect(route('usuarios.index'));

        $cuenta->refresh();
        $this->assertSame('Nombre Nuevo', $cuenta->name);
        $this->assertEqualsCanonicalizing(['Jefe de Zona', 'Administrador'], $cuenta->getRoleNames()->all());
        $this->assertSame($hashAntes, $cuenta->password);
    }

    public function test_edit_form_preselects_every_role_of_the_account(): void
    {
        $cuenta = $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $html = $this->get(route('usuarios.edit', $cuenta))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="roles\[\]"[^>]*value="Jefe de Zona"[^>]*checked|checked[^>]*name="roles\[\]"[^>]*value="Jefe de Zona"/s', $html);
        $this->assertMatchesRegularExpression('/name="roles\[\]"[^>]*value="Administrador"[^>]*checked|checked[^>]*name="roles\[\]"[^>]*value="Administrador"/s', $html);
    }

    public function test_admin_can_create_an_account_with_jefe_and_admin_together(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->post(route('usuarios.store'), [
            'name' => 'Otra Jefatura',
            'email' => 'otra@example.com',
            'roles' => ['Jefe de Zona', 'Administrador'],
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ])->assertRedirect(route('usuarios.index'));

        $nueva = User::where('email', 'otra@example.com')->firstOrFail();
        $this->assertEqualsCanonicalizing(['Jefe de Zona', 'Administrador'], $nueva->getRoleNames()->all());
    }

    public function test_legacy_single_role_field_is_still_accepted(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->post(route('usuarios.store'), [
            'name' => 'Legado',
            'email' => 'legado@example.com',
            'role' => 'Jefe de Zona',
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ])->assertRedirect(route('usuarios.index'));

        $this->assertSame(['Jefe de Zona'], User::where('email', 'legado@example.com')->firstOrFail()->getRoleNames()->all());
    }

    public function test_estacion_role_cannot_be_combined_with_other_roles(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaJefatura());
        $estacion = Estacion::factory()->create(['is_operativa' => true]);

        $this->post(route('usuarios.store'), [
            'name' => 'Mezcla',
            'email' => 'mezcla@example.com',
            'roles' => ['Estación', 'Administrador'],
            'estacion_id' => $estacion->id,
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ])->assertInvalid(['roles']);
    }

    public function test_edificio_role_cannot_be_combined_with_other_roles(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->post(route('usuarios.store'), [
            'name' => 'Mezcla',
            'email' => 'mezcla@example.com',
            'roles' => ['Edificio', 'Jefe de Zona'],
            'password' => 'ContraseñaSegura123!',
            'password_confirmation' => 'ContraseñaSegura123!',
        ])->assertInvalid(['roles']);
    }

    public function test_admin_cannot_remove_own_administrador_role(): void
    {
        $cuenta = $this->actingAsTwoFactorVerified($this->cuentaJefatura());
        $otro = User::factory()->create();
        $otro->assignRole('Administrador');

        $this->put(route('usuarios.update', $cuenta), $this->payload($cuenta, ['roles' => ['Jefe de Zona']]))
            ->assertInvalid(['roles']);

        $this->assertTrue($cuenta->fresh()->hasRole('Administrador'));
    }

    public function test_last_administrador_cannot_lose_the_role(): void
    {
        // Único Administrador del sistema: ni siquiera él puede quitarse el rol.
        $unico = $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->put(route('usuarios.update', $unico), $this->payload($unico, ['roles' => ['Jefe de Zona']]))
            ->assertInvalid(['roles']);

        $this->assertTrue($unico->fresh()->hasRole('Administrador'));
    }

    public function test_last_administrador_cannot_be_deleted(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Administrador');
        $this->actingAsTwoFactorVerified($actor);
        $objetivo = User::factory()->create();
        $objetivo->assignRole('Administrador');

        // Hay dos administradores: borrar a uno se permite.
        $this->delete(route('usuarios.destroy', $objetivo))->assertRedirect(route('usuarios.index'));

        // Con un solo Administrador no hay a quién borrar salvo a sí mismo (ya bloqueado),
        // así que la guarda del "último" se prueba por política directa.
        $solo = User::factory()->create();
        $solo->assignRole('Administrador');
        $actor->removeRole('Administrador');
        $actor->assignRole('Jefe de Zona');

        $this->assertFalse((new UserPolicy)->delete($actor, $solo));
    }

    public function test_role_change_is_logged_with_old_and_new_role_arrays(): void
    {
        $cuenta = $this->actingAsTwoFactorVerified($this->cuentaJefatura());
        $objetivo = User::factory()->create();
        $objetivo->assignRole('Jefe de Zona');

        $this->put(route('usuarios.update', $objetivo), $this->payload($objetivo, ['roles' => ['Jefe de Zona', 'Administrador']]));

        $activity = Activity::where('subject_id', $objetivo->id)->where('event', 'role_assigned')->latest('id')->firstOrFail();
        $this->assertSame(['Jefe de Zona'], $activity->properties->get('old')['roles']);
        $this->assertEqualsCanonicalizing(['Jefe de Zona', 'Administrador'], $activity->properties->get('attributes')['roles']);
        $this->assertNotNull($cuenta);
    }

    public function test_unchanged_roles_do_not_write_a_role_log(): void
    {
        $cuenta = $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->put(route('usuarios.update', $cuenta), $this->payload($cuenta));

        $this->assertSame(0, Activity::where('subject_id', $cuenta->id)->where('event', 'role_assigned')->count());
    }

    public function test_jefe_admin_account_keeps_access_to_the_main_areas(): void
    {
        $this->actingAsTwoFactorVerified($this->cuentaJefatura());

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('usuarios.index'))->assertOk();
        $this->get(route('secciones.administrar'))->assertOk();
        $this->get(route('asistencia.zona.index'))->assertOk();
    }
}
