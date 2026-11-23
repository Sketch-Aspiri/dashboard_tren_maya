<?php

namespace Tests\Feature;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TurnoAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * El turno (primer/segundo) se captura por persona y día, igual que en el Excel de control: la hoja de Google
 * escribe en la mañana solo a quienes están en el primer turno.
 */
class AsistenciaTurnoTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    private Empleado $empleado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->estacion = Estacion::factory()->create(['is_operativa' => true]);
        $this->empleado = Empleado::factory()->create([
            'estacion_id' => $this->estacion->id,
            'estatus' => EmpleadoEstatus::Activo->value,
        ]);

        $user = User::factory()->create(['estacion_id' => $this->estacion->id]);
        $user->assignRole('Estación');
        $this->actingAsTwoFactorVerified($user);
    }

    /** @param array<string, mixed> $extra */
    private function guardar(array $extra = [], ?CarbonImmutable $fecha = null): TestResponse
    {
        return $this->put(route('asistencia.captura.update'), [
            'fecha' => ($fecha ?? CarbonImmutable::today())->toDateString(),
            'registros' => [
                $this->empleado->id => array_merge([
                    'empleado_id' => $this->empleado->id,
                    'estatus' => EstatusAsistencia::Presente->value,
                ], $extra),
            ],
        ]);
    }

    public function test_el_turno_capturado_se_guarda(): void
    {
        $this->guardar(['turno' => 'segundo'])->assertRedirect();

        $this->assertDatabaseHas('registros_diarios', [
            'empleado_id' => $this->empleado->id,
            'turno' => TurnoAsistencia::Segundo->value,
        ]);
    }

    public function test_un_turno_invalido_se_rechaza(): void
    {
        $this->guardar(['turno' => 'tercero'])->assertSessionHasErrors('registros.'.$this->empleado->id.'.turno');

        $this->assertDatabaseCount('registros_diarios', 0);
    }

    public function test_sin_turno_se_usa_el_ultimo_conocido_de_la_persona(): void
    {
        RegistroDiario::factory()->create([
            'empleado_id' => $this->empleado->id,
            'estacion_id' => $this->estacion->id,
            'fecha' => CarbonImmutable::today()->subDay()->toDateString(),
            'turno' => TurnoAsistencia::Segundo->value,
        ]);

        $this->guardar()->assertRedirect();

        $this->assertSame(
            TurnoAsistencia::Segundo,
            RegistroDiario::query()->whereDate('fecha', CarbonImmutable::today())->firstOrFail()->turno,
        );
    }

    public function test_sin_turno_ni_historial_es_primer_turno(): void
    {
        $this->guardar()->assertRedirect();

        $this->assertDatabaseHas('registros_diarios', ['empleado_id' => $this->empleado->id, 'turno' => 'primer']);
    }

    public function test_editar_sin_turno_conserva_el_turno_ya_guardado(): void
    {
        $this->guardar(['turno' => 'segundo']);
        $this->guardar(['estatus' => EstatusAsistencia::Descanso->value]);

        $this->assertDatabaseHas('registros_diarios', [
            'empleado_id' => $this->empleado->id,
            'estatus' => 'descanso',
            'turno' => 'segundo',
        ]);
    }

    public function test_la_pantalla_ofrece_el_turno_y_precarga_el_ultimo(): void
    {
        RegistroDiario::factory()->create([
            'empleado_id' => $this->empleado->id,
            'estacion_id' => $this->estacion->id,
            'fecha' => CarbonImmutable::today()->subDay()->toDateString(),
            'turno' => TurnoAsistencia::Segundo->value,
        ]);

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('registros[${p.id}][turno]', false)
            ->assertSee('\u0022turno\u0022:\u0022segundo\u0022', false);
    }
}
