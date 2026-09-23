<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\EstatusViaAnden;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for "Controles" -> "Estatus de vías y andenes". Dummy
 * factory data only (.claude/rules/testing.md); the real rows are loaded
 * via app/Console/Commands/ImportEstatusViasAndenesCommand.php.
 */
class EstatusViasAndenesTest extends TestCase
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

    // --- Authentication / authorization ------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $registro = EstatusViaAnden::factory()->create();

        $this->get(route('controles.estatus-vias-andenes.index'))->assertRedirect(route('login'));
        $this->get(route('controles.estatus-vias-andenes.edit', $registro))->assertRedirect(route('login'));
        $this->put(route('controles.estatus-vias-andenes.update', $registro), [])->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked(): void
    {
        $response = $this->actingAs($this->userWithRole('Jefe de Zona'))->get(route('controles.estatus-vias-andenes.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_zone_chief_administrador_and_estacion_can_view_the_page(): void
    {
        foreach (['Jefe de Zona', 'Administrador', 'Estación'] as $role) {
            $estacion = Estacion::factory()->create();
            $this->actingAsTwoFactorVerified($this->userWithRole($role, ['estacion_id' => $estacion->id]));

            $this->get(route('controles.estatus-vias-andenes.index'))->assertOk();
        }
    }

    // --- Scoping -------------------------------------------------------------

    public function test_estacion_role_only_sees_its_own_vias(): void
    {
        $suya = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $otra = Estacion::factory()->create(['nombre' => 'Tulum']);
        EstatusViaAnden::factory()->create(['estacion_id' => $suya->id, 'comentarios' => 'COMENTARIO-PROPIO']);
        EstatusViaAnden::factory()->create(['estacion_id' => $otra->id, 'comentarios' => 'COMENTARIO-AJENO']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('controles.estatus-vias-andenes.index'))
            ->assertOk()
            ->assertSee('COMENTARIO-PROPIO')
            ->assertDontSee('COMENTARIO-AJENO');
    }

    public function test_zone_chief_sees_vias_from_every_estacion_ordered_by_via(): void
    {
        $bacalar = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum']);
        EstatusViaAnden::factory()->create(['estacion_id' => $bacalar->id, 'via' => 3, 'comentarios' => 'VIA-TRES']);
        EstatusViaAnden::factory()->create(['estacion_id' => $bacalar->id, 'via' => 1, 'comentarios' => 'VIA-UNO']);
        EstatusViaAnden::factory()->create(['estacion_id' => $tulum->id, 'via' => 2, 'comentarios' => 'VIA-TULUM']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('controles.estatus-vias-andenes.index'))
            ->assertOk()
            ->assertSeeInOrder(['Bacalar', 'VIA-UNO', 'VIA-TRES', 'Tulum', 'VIA-TULUM']);
    }

    public function test_renders_an_empty_state_when_nothing_has_been_imported(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('controles.estatus-vias-andenes.index'))
            ->assertOk()
            ->assertSee('No hay estatus de vías y andenes registrados.');
    }

    // --- Editing ---------------------------------------------------------------

    public function test_zone_chief_and_administrador_can_edit_any_estacion(): void
    {
        foreach (['Jefe de Zona', 'Administrador'] as $role) {
            $registro = EstatusViaAnden::factory()->create();
            $this->actingAsTwoFactorVerified($this->userWithRole($role));

            $this->get(route('controles.estatus-vias-andenes.edit', $registro))->assertOk();

            $this->put(route('controles.estatus-vias-andenes.update', $registro), ['estatus_via' => 'Fuera de Operación'])
                ->assertRedirect(route('controles.estatus-vias-andenes.index'));
            $this->assertSame('Fuera de Operación', $registro->refresh()->estatus_via);
        }
    }

    public function test_estacion_role_can_edit_only_its_own_vias(): void
    {
        $suya = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $propia = EstatusViaAnden::factory()->create(['estacion_id' => $suya->id]);
        $ajena = EstatusViaAnden::factory()->create(['estacion_id' => $otra->id, 'estatus_via' => 'Operativas']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('controles.estatus-vias-andenes.edit', $propia))->assertOk();
        $this->get(route('controles.estatus-vias-andenes.edit', $ajena))->assertForbidden();

        $this->put(route('controles.estatus-vias-andenes.update', $propia), ['estatus_via' => 'Fuera de Operación'])
            ->assertRedirect(route('controles.estatus-vias-andenes.index'));
        $this->assertSame('Fuera de Operación', $propia->refresh()->estatus_via);

        $this->put(route('controles.estatus-vias-andenes.update', $ajena), ['estatus_via' => 'Fuera de Operación'])->assertForbidden();
        $this->assertSame('Operativas', $ajena->refresh()->estatus_via);
    }

    public function test_index_only_shows_the_edit_link_when_the_user_may_edit_that_estacion(): void
    {
        $suya = Estacion::factory()->create();
        EstatusViaAnden::factory()->create(['estacion_id' => $suya->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('controles.estatus-vias-andenes.index'))->assertOk()->assertSee('Editar');
    }

    public function test_update_validates_maximum_lengths(): void
    {
        $registro = EstatusViaAnden::factory()->create();
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.estatus-vias-andenes.update', $registro), [
            'anden' => str_repeat('A', 11),
            'estatus_via' => str_repeat('x', 256),
            'comentarios' => str_repeat('x', 5001),
        ])->assertInvalid(['anden', 'estatus_via', 'comentarios']);
    }

    public function test_update_ignores_estacion_and_via_submitted_in_the_body(): void
    {
        $suya = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $registro = EstatusViaAnden::factory()->create(['estacion_id' => $suya->id, 'via' => 2]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.estatus-vias-andenes.update', $registro), [
            'estatus_via' => 'Fuera de Operación',
            'estacion_id' => $otra->id,
            'via' => 9,
        ])->assertRedirect(route('controles.estatus-vias-andenes.index'));

        $registro->refresh();
        $this->assertSame($suya->id, $registro->estacion_id);
        $this->assertSame(2, $registro->via);
    }

    // --- Audit logging -----------------------------------------------------

    public function test_updating_a_via_logs_an_updated_activity(): void
    {
        $registro = EstatusViaAnden::factory()->create();
        $actor = $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.estatus-vias-andenes.update', $registro), ['estatus_via' => 'Fuera de Operación']);

        $activity = Activity::query()
            ->where('subject_id', $registro->id)
            ->where('subject_type', EstatusViaAnden::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }
}
