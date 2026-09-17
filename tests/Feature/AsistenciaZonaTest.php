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
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the zona-wide "¿quién ya capturó hoy?" oversight
 * board (Etapa 3 — Control de Asistencia Diaria, see the approved plan).
 * Read-only screen: Jefe de Zona and Administrador only, per the
 * `view-asistencia-zona` Gate. Uses only dummy factory data.
 */
class AsistenciaZonaTest extends TestCase
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

    // --- Authentication ----------------------------------------------------

    public function test_guest_is_redirected_from_zona_index(): void
    {
        $response = $this->get(route('asistencia.zona.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_zona_data(): void
    {
        $response = $this->get(route('asistencia.zona.data'));

        $response->assertRedirect(route('login'));
    }

    public function test_guest_is_redirected_from_zona_show(): void
    {
        $estacion = $this->estacionOperativa();

        $response = $this->get(route('asistencia.zona.show', $estacion));

        $response->assertRedirect(route('login'));
    }

    public function test_user_without_completed_two_factor_is_blocked_from_zona_index(): void
    {
        $this->actingAs($this->zoneChief());

        $response = $this->get(route('asistencia.zona.index'));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    // --- Authorization: Gate denial for Estación / no role -----------------

    public function test_estacion_user_cannot_view_zona_index(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.zona.index'));

        $response->assertForbidden();
    }

    public function test_estacion_user_cannot_view_zona_data(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.zona.data'));

        $response->assertForbidden();
    }

    public function test_estacion_user_cannot_view_zona_show(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->estacionUser($estacion));

        $response = $this->get(route('asistencia.zona.show', $estacion));

        $response->assertForbidden();
    }

    public function test_user_without_a_role_cannot_view_zona_index(): void
    {
        $this->actingAsTwoFactorVerified(User::factory()->create());

        $response = $this->get(route('asistencia.zona.index'));

        $response->assertForbidden();
    }

    // --- Authorization: Jefe de Zona and Administrador can both read -------

    public function test_zone_chief_can_view_zona_index(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('asistencia.zona.index'));

        $response->assertOk();
    }

    public function test_administrador_can_view_zona_index(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('asistencia.zona.index'));

        $response->assertOk();
    }

    public function test_zone_chief_can_view_zona_data(): void
    {
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('asistencia.zona.data'));

        $response->assertOk();
    }

    public function test_administrador_can_view_zona_show(): void
    {
        $estacion = $this->estacionOperativa();
        $this->empleadoActivo($estacion, ['nombre_completo' => 'Persona De Prueba']);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('asistencia.zona.show', $estacion));

        $response->assertOk();
        $response->assertSee('Persona De Prueba');
    }

    public function test_zone_chief_can_view_zona_show(): void
    {
        $estacion = $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->get(route('asistencia.zona.show', $estacion));

        $response->assertOk();
    }

    // --- Captured/not-captured derivation -----------------------------------

    public function test_resumen_reflects_partial_capture_as_not_captured(): void
    {
        $estacion = $this->estacionOperativa();
        $empleadoUno = $this->empleadoActivo($estacion);
        $this->empleadoActivo($estacion); // segundo empleado sin registro aún

        RegistroDiario::factory()->create([
            'empleado_id' => $empleadoUno->id,
            'estacion_id' => $estacion->id,
            'fecha' => CarbonImmutable::today(),
            'estatus' => EstatusAsistencia::Presente->value,
        ]);

        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->getJson(route('asistencia.zona.data'));

        $response->assertOk();
        $fila = collect($response->json('data'))->firstWhere('estacion_id', $estacion->id);

        $this->assertSame(2, $fila['total_roster']);
        $this->assertSame(1, $fila['total_capturado']);
        $this->assertFalse($fila['capturado']);
    }

    public function test_resumen_reflects_full_capture_as_captured(): void
    {
        $estacion = $this->estacionOperativa();
        $empleadoUno = $this->empleadoActivo($estacion);
        $empleadoDos = $this->empleadoActivo($estacion);

        foreach ([$empleadoUno, $empleadoDos] as $empleado) {
            RegistroDiario::factory()->create([
                'empleado_id' => $empleado->id,
                'estacion_id' => $estacion->id,
                'fecha' => CarbonImmutable::today(),
                'estatus' => EstatusAsistencia::Presente->value,
            ]);
        }

        $this->actingAsTwoFactorVerified($this->zoneChief());

        $response = $this->getJson(route('asistencia.zona.data'));

        $response->assertOk();
        $fila = collect($response->json('data'))->firstWhere('estacion_id', $estacion->id);

        $this->assertSame(2, $fila['total_roster']);
        $this->assertSame(2, $fila['total_capturado']);
        $this->assertTrue($fila['capturado']);
    }

    public function test_estacion_with_zero_active_roster_does_not_crash_and_is_not_marked_captured(): void
    {
        $estacionVacia = $this->estacionOperativa();

        $this->actingAsTwoFactorVerified($this->zoneChief());

        $indexResponse = $this->get(route('asistencia.zona.index'));
        $indexResponse->assertOk();

        $dataResponse = $this->getJson(route('asistencia.zona.data'));
        $dataResponse->assertOk();

        $fila = collect($dataResponse->json('data'))->firstWhere('estacion_id', $estacionVacia->id);

        $this->assertSame(0, $fila['total_roster']);
        $this->assertSame(0, $fila['total_capturado']);
        $this->assertFalse($fila['capturado']);

        $showResponse = $this->get(route('asistencia.zona.show', $estacionVacia));
        $showResponse->assertOk();
    }

    // --- show() renders "Sin capturar" when nothing captured yet -----------

    public function test_show_renders_sin_capturar_when_nothing_captured_yet(): void
    {
        // No RegistroDiario is created for this empleado at all — the
        // status is never assumed to be "Presente" just because nobody
        // has captured anything yet.
        $estacion = $this->estacionOperativa();
        $this->empleadoActivo($estacion, ['nombre_completo' => 'Sin Registro Aun']);
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->get(route('asistencia.zona.show', $estacion));

        $response->assertOk();
        $response->assertSee('Sin Registro Aun');
        $response->assertSee('Sin capturar');
        $response->assertDontSee(EstatusAsistencia::Presente->label());
    }

    // --- JSON envelope shape -------------------------------------------------

    public function test_zona_data_returns_the_standard_json_envelope(): void
    {
        $this->estacionOperativa();
        $this->actingAsTwoFactorVerified($this->administrador());

        $response = $this->getJson(route('asistencia.zona.data'));

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data',
            'error',
            'meta' => ['fecha'],
        ]);
        $response->assertJson(['success' => true, 'error' => null]);
    }

    // --- Rate limiting ---------------------------------------------------------

    public function test_zona_data_is_rate_limited(): void
    {
        $this->actingAsTwoFactorVerified($this->administrador());

        // RateLimiter::for('asistencia-zona-data') allows 60/minute per
        // user (see AppServiceProvider::configureRateLimiting()) — trips
        // it directly rather than only asserting the middleware is
        // attached, since a real 429 is cheap to produce here.
        for ($i = 0; $i < 60; $i++) {
            $this->getJson(route('asistencia.zona.data'))->assertOk();
        }

        $response = $this->getJson(route('asistencia.zona.data'));

        $response->assertStatus(429);
    }
}
