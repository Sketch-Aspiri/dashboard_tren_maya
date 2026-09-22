<?php

namespace Tests\Feature;

use App\Models\Elevador;
use App\Models\EscaleraElectrica;
use App\Models\Estacion;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the "Controles" module (Escaleras eléctricas /
 * Elevadores, read-only inventory). Dummy factory data only
 * (.claude/rules/testing.md); the real records are loaded via
 * app/Console/Commands/ImportControlesCommand.php.
 */
class ControlesTest extends TestCase
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
        $this->get(route('controles.escaleras-electricas.index'))->assertRedirect(route('login'));
        $this->get(route('controles.elevadores.index'))->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked(): void
    {
        $response = $this->actingAs($this->userWithRole('Jefe de Zona'))->get(route('controles.escaleras-electricas.index'));

        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_zone_chief_administrador_and_estacion_can_view_both_pages(): void
    {
        foreach (['Jefe de Zona', 'Administrador', 'Estación'] as $role) {
            $estacion = Estacion::factory()->create();
            $this->actingAsTwoFactorVerified($this->userWithRole($role, ['estacion_id' => $estacion->id]));

            $this->get(route('controles.escaleras-electricas.index'))->assertOk();
            $this->get(route('controles.elevadores.index'))->assertOk();
        }
    }

    // --- Scoping -------------------------------------------------------------

    public function test_estacion_role_only_sees_its_own_equipment(): void
    {
        $suya = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $otra = Estacion::factory()->create(['nombre' => 'Tulum']);

        EscaleraElectrica::factory()->create(['estacion_id' => $suya->id, 'identificador' => 'ESC-PROPIA']);
        EscaleraElectrica::factory()->create(['estacion_id' => $otra->id, 'identificador' => 'ESC-AJENA']);
        Elevador::factory()->create(['estacion_id' => $suya->id, 'identificador' => 'ELE-PROPIA']);
        Elevador::factory()->create(['estacion_id' => $otra->id, 'identificador' => 'ELE-AJENA']);

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('controles.escaleras-electricas.index'))
            ->assertOk()
            ->assertSee('ESC-PROPIA')
            ->assertDontSee('ESC-AJENA');

        $this->get(route('controles.elevadores.index'))
            ->assertOk()
            ->assertSee('ELE-PROPIA')
            ->assertDontSee('ELE-AJENA');
    }

    public function test_zone_chief_sees_equipment_from_every_estacion(): void
    {
        $bacalar = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $tulum = Estacion::factory()->create(['nombre' => 'Tulum']);

        EscaleraElectrica::factory()->create(['estacion_id' => $bacalar->id, 'identificador' => 'ESC-BACALAR']);
        EscaleraElectrica::factory()->create(['estacion_id' => $tulum->id, 'identificador' => 'ESC-TULUM']);

        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('controles.escaleras-electricas.index'))
            ->assertOk()
            ->assertSee('ESC-BACALAR')
            ->assertSee('ESC-TULUM');
    }

    // --- Listing ---------------------------------------------------------------

    public function test_shows_the_operativo_status_and_maintenance_date(): void
    {
        $estacion = Estacion::factory()->create(['nombre' => 'Tulum']);
        EscaleraElectrica::factory()->create([
            'estacion_id' => $estacion->id,
            'identificador' => 'IBD2301245',
            'operativo' => 'SI',
            'fecha_ultimo_mantenimiento' => '2025-09-27',
            'observaciones' => 'Fuera de servicio, debido a que el andén A no está en operación.',
        ]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('controles.escaleras-electricas.index'))
            ->assertOk()
            ->assertSee('Tulum')
            ->assertSee('IBD2301245')
            ->assertSee('27/09/2025')
            ->assertSee('Fuera de servicio, debido a que el andén A no está en operación.');
    }

    public function test_renders_an_empty_state_when_nothing_has_been_imported(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->get(route('controles.escaleras-electricas.index'))
            ->assertOk()
            ->assertSee('No hay escaleras eléctricas registradas.');

        $this->get(route('controles.elevadores.index'))
            ->assertOk()
            ->assertSee('No hay elevadores registrados.');
    }

    // --- Editing ---------------------------------------------------------------

    public function test_zone_chief_and_administrador_can_edit_any_estacion(): void
    {
        foreach (['Jefe de Zona', 'Administrador'] as $role) {
            $estacion = Estacion::factory()->create();
            $escalera = EscaleraElectrica::factory()->create(['estacion_id' => $estacion->id]);
            $elevador = Elevador::factory()->create(['estacion_id' => $estacion->id]);
            $this->actingAsTwoFactorVerified($this->userWithRole($role));

            $this->get(route('controles.escaleras-electricas.edit', $escalera))->assertOk();
            $this->get(route('controles.elevadores.edit', $elevador))->assertOk();

            $this->put(route('controles.escaleras-electricas.update', $escalera), ['operativo' => 'NO'])
                ->assertRedirect(route('controles.escaleras-electricas.index'));
            $this->assertSame('NO', $escalera->refresh()->operativo);

            $this->put(route('controles.elevadores.update', $elevador), ['operativo' => 'NO'])
                ->assertRedirect(route('controles.elevadores.index'));
            $this->assertSame('NO', $elevador->refresh()->operativo);
        }
    }

    public function test_estacion_role_can_edit_only_its_own_equipment(): void
    {
        $suya = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $escaleraPropia = EscaleraElectrica::factory()->create(['estacion_id' => $suya->id]);
        $escaleraAjena = EscaleraElectrica::factory()->create(['estacion_id' => $otra->id]);
        $elevadorPropio = Elevador::factory()->create(['estacion_id' => $suya->id]);
        $elevadorAjeno = Elevador::factory()->create(['estacion_id' => $otra->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $suya->id]));

        $this->get(route('controles.escaleras-electricas.edit', $escaleraPropia))->assertOk();
        $this->get(route('controles.escaleras-electricas.edit', $escaleraAjena))->assertForbidden();
        $this->get(route('controles.elevadores.edit', $elevadorPropio))->assertOk();
        $this->get(route('controles.elevadores.edit', $elevadorAjeno))->assertForbidden();

        $this->put(route('controles.escaleras-electricas.update', $escaleraPropia), ['operativo' => 'NO'])
            ->assertRedirect(route('controles.escaleras-electricas.index'));
        $this->assertSame('NO', $escaleraPropia->refresh()->operativo);

        $this->put(route('controles.escaleras-electricas.update', $escaleraAjena), ['operativo' => 'NO'])->assertForbidden();
        $this->assertNotSame('NO', $escaleraAjena->refresh()->operativo);

        $this->put(route('controles.elevadores.update', $elevadorAjeno), ['operativo' => 'NO'])->assertForbidden();
        $this->assertNotSame('NO', $elevadorAjeno->refresh()->operativo);
    }

    public function test_index_only_shows_the_edit_link_when_the_user_may_edit_that_estacion(): void
    {
        $suya = Estacion::factory()->create(['nombre' => 'Bacalar']);
        $otra = Estacion::factory()->create(['nombre' => 'Tulum']);
        EscaleraElectrica::factory()->create(['estacion_id' => $suya->id, 'identificador' => 'ESC-PROPIA']);
        EscaleraElectrica::factory()->create(['estacion_id' => $otra->id, 'identificador' => 'ESC-AJENA']);
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        // Jefe de Zona may edit every estación, so both rows get a link.
        $response = $this->get(route('controles.escaleras-electricas.index'))->assertOk();
        $response->assertSeeInOrder(['ESC-PROPIA', 'Editar', 'ESC-AJENA', 'Editar']);
    }

    public function test_update_validates_the_year_and_date(): void
    {
        $estacion = Estacion::factory()->create();
        $escalera = EscaleraElectrica::factory()->create(['estacion_id' => $estacion->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.escaleras-electricas.update', $escalera), ['anio_instalacion' => 1899])
            ->assertInvalid(['anio_instalacion']);

        $this->put(route('controles.escaleras-electricas.update', $escalera), ['fecha_ultimo_mantenimiento' => 'no-es-una-fecha'])
            ->assertInvalid(['fecha_ultimo_mantenimiento']);
    }

    public function test_update_ignores_an_estacion_id_submitted_in_the_body(): void
    {
        $suya = Estacion::factory()->create();
        $otra = Estacion::factory()->create();
        $escalera = EscaleraElectrica::factory()->create(['estacion_id' => $suya->id]);
        $elevador = Elevador::factory()->create(['estacion_id' => $suya->id]);
        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.escaleras-electricas.update', $escalera), ['operativo' => 'NO', 'estacion_id' => $otra->id])
            ->assertRedirect(route('controles.escaleras-electricas.index'));
        $this->assertSame($suya->id, $escalera->refresh()->estacion_id);

        $this->put(route('controles.elevadores.update', $elevador), ['operativo' => 'NO', 'estacion_id' => $otra->id])
            ->assertRedirect(route('controles.elevadores.index'));
        $this->assertSame($suya->id, $elevador->refresh()->estacion_id);
    }

    // --- Audit logging -----------------------------------------------------

    public function test_updating_an_escalera_electrica_logs_an_updated_activity(): void
    {
        $estacion = Estacion::factory()->create();
        $escalera = EscaleraElectrica::factory()->create(['estacion_id' => $estacion->id]);
        $actor = $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.escaleras-electricas.update', $escalera), ['operativo' => 'NO']);

        $activity = Activity::query()
            ->where('subject_id', $escalera->id)
            ->where('subject_type', EscaleraElectrica::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }

    public function test_updating_an_elevador_logs_an_updated_activity(): void
    {
        $estacion = Estacion::factory()->create();
        $elevador = Elevador::factory()->create(['estacion_id' => $estacion->id]);
        $actor = $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));

        $this->put(route('controles.elevadores.update', $elevador), ['operativo' => 'NO']);

        $activity = Activity::query()
            ->where('subject_id', $elevador->id)
            ->where('subject_type', Elevador::class)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($activity);
        $this->assertSame($actor->id, $activity->causer_id);
    }
}
