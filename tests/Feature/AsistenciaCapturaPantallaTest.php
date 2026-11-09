<?php

namespace Tests\Feature;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Models\User;
use App\Services\AsistenciaCapturaService;
use App\Services\CapturaAsistenciaEstado;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class AsistenciaCapturaPantallaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Estación');
        $this->estacion = Estacion::factory()->create(['nombre' => 'Bacalar', 'is_operativa' => true]);
        $this->usuario = User::factory()->create(['estacion_id' => $this->estacion->id]);
        $this->usuario->assignRole('Estación');
        $this->actingAsTwoFactorVerified($this->usuario);
    }

    private function empleado(array $datos = []): Empleado
    {
        return Empleado::factory()->create(array_merge([
            'estacion_id' => $this->estacion->id,
            'tipo_plaza' => TipoPlaza::Permanente->value,
        ], $datos));
    }

    private function estado(): array
    {
        $fecha = CarbonImmutable::today();
        $servicio = app(AsistenciaCapturaService::class);

        return app(CapturaAsistenciaEstado::class)->construir(
            $this->estacion,
            $fecha,
            $servicio->rosterFor($this->estacion, $fecha),
            $servicio->comisionadosVisitantesFor($this->estacion, $fecha),
            $servicio->comisionadosFueraFor($this->estacion, $fecha),
            [],
            ['numero' => 'X/1', 'firmante_cargo' => 'Cargo', 'firmante_nombre' => 'Nombre'],
        );
    }

    public function test_estacion_agrega_comisionado_por_ajax_y_recibe_la_fila(): void
    {
        $empleado = $this->empleado();

        $respuesta = $this->postJson(route('asistencia.captura.comisionados.store'), [
            'fecha' => now()->toDateString(),
            'empleado_id' => $empleado->id,
            'coordinacion_destino' => 'CGOFP',
            'ubicacion_destino' => 'CANCÚN',
        ]);

        $respuesta->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('error', null)
            ->assertJsonPath('data.empleado_id', $empleado->id)
            ->assertJsonPath('data.coordinacion_destino', 'CGOFP');
        $this->assertDatabaseHas('comisionados_fuera', ['empleado_id' => $empleado->id, 'estacion_id' => $this->estacion->id]);
    }

    public function test_comisionado_invalido_por_ajax_devuelve_422_con_errores(): void
    {
        $this->postJson(route('asistencia.captura.comisionados.store'), ['fecha' => now()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empleado_id');
    }

    public function test_estacion_no_puede_comisionar_personal_de_otra_estacion_por_ajax(): void
    {
        $ajeno = Empleado::factory()->create(['estacion_id' => Estacion::factory()->create()->id]);

        $this->postJson(route('asistencia.captura.comisionados.store'), [
            'fecha' => now()->toDateString(),
            'empleado_id' => $ajeno->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('empleado_id');
    }

    public function test_estacion_edita_y_quita_comisionado_por_ajax(): void
    {
        $comision = ComisionadoFuera::factory()->create([
            'empleado_id' => $this->empleado()->id,
            'estacion_id' => $this->estacion->id,
            'fecha' => now()->toDateString(),
        ]);

        $this->putJson(route('asistencia.captura.comisionados.update', $comision), [
            'empleado_id' => $comision->empleado_id,
            'coordinacion_destino' => 'CGGIF',
        ])->assertOk()->assertJsonPath('data.coordinacion_destino', 'CGGIF');

        $this->deleteJson(route('asistencia.captura.comisionados.destroy', $comision))->assertOk()->assertJsonPath('data', null);
        $this->assertDatabaseMissing('comisionados_fuera', ['id' => $comision->id]);
    }

    public function test_estacion_no_edita_comisionado_de_otra_estacion_por_ajax(): void
    {
        $otra = Estacion::factory()->create();
        $comision = ComisionadoFuera::factory()->create([
            'empleado_id' => Empleado::factory()->create(['estacion_id' => $otra->id])->id,
            'estacion_id' => $otra->id,
            'fecha' => now()->toDateString(),
        ]);

        $this->deleteJson(route('asistencia.captura.comisionados.destroy', $comision))->assertForbidden();
        $this->assertDatabaseHas('comisionados_fuera', ['id' => $comision->id]);
    }

    public function test_estacion_agrega_y_quita_visitante_por_ajax(): void
    {
        $id = $this->postJson(route('asistencia.captura.visitantes.store'), [
            'fecha' => now()->toDateString(),
            'nombre' => 'Visita De Prueba',
            'direccion_origen' => 'CGGIF',
        ])->assertCreated()->assertJsonPath('data.nombre', 'Visita De Prueba')->json('data.id');

        $this->deleteJson(route('asistencia.captura.visitantes.destroy', $id))->assertOk();
        $this->assertDatabaseMissing('comisionados_visitantes', ['id' => $id]);
    }

    public function test_el_formulario_clasico_sigue_redirigiendo(): void
    {
        $this->post(route('asistencia.captura.visitantes.store'), [
            'fecha' => now()->toDateString(),
            'nombre' => 'Visita De Prueba',
        ])->assertRedirect();
    }

    public function test_el_estado_clasifica_la_plaza_y_marca_al_gerente(): void
    {
        $gerente = $this->empleado(['puesto' => 'Gerencia de Estación A Bacalar']);
        $eventual = $this->empleado(['tipo_plaza' => TipoPlaza::Eventual->value, 'puesto' => 'Ayudante']);

        $personas = collect($this->estado()['personas'])->keyBy('id');

        $this->assertTrue($personas[$gerente->id]['destacada']);
        $this->assertSame('permanentes', $personas[$gerente->id]['grupo']);
        $this->assertSame('eventuales', $personas[$eventual->id]['grupo']);
        $this->assertFalse($personas[$eventual->id]['destacada']);
        $this->assertSame('', $personas[$eventual->id]['estatus']);
    }

    public function test_sugiere_continuar_un_periodo_vigente_sin_aplicarlo(): void
    {
        $empleado = $this->empleado();
        RegistroDiario::factory()->create([
            'empleado_id' => $empleado->id,
            'estacion_id' => $this->estacion->id,
            'fecha' => now()->subDay()->toDateString(),
            'estatus' => EstatusAsistencia::Vacaciones->value,
            'fecha_inicio' => now()->subDay()->toDateString(),
            'fecha_fin' => now()->addDays(3)->toDateString(),
        ]);

        $persona = $this->estado()['personas'][0];

        $this->assertSame('', $persona['estatus']);
        $this->assertSame('vacaciones', $persona['continuidad']['estatus']);
    }

    public function test_no_sugiere_periodos_vencidos_ni_si_ya_hay_captura_de_hoy(): void
    {
        $vencido = $this->empleado();
        RegistroDiario::factory()->create([
            'empleado_id' => $vencido->id,
            'estacion_id' => $this->estacion->id,
            'fecha' => now()->subDays(3)->toDateString(),
            'estatus' => EstatusAsistencia::Vacaciones->value,
            'fecha_fin' => now()->subDay()->toDateString(),
        ]);
        $capturado = $this->empleado();
        foreach ([now()->subDay(), now()] as $dia) {
            RegistroDiario::factory()->create([
                'empleado_id' => $capturado->id,
                'estacion_id' => $this->estacion->id,
                'fecha' => $dia->toDateString(),
                'estatus' => EstatusAsistencia::Vacaciones->value,
                'fecha_fin' => now()->addDays(2)->toDateString(),
            ]);
        }

        $this->assertSame([null, null], array_column($this->estado()['personas'], 'continuidad'));
    }

    public function test_ofrece_solo_comisiones_anteriores_vigentes_de_la_propia_estacion(): void
    {
        $crear = fn (Estacion $estacion, ?string $fin) => ComisionadoFuera::factory()->create([
            'empleado_id' => Empleado::factory()->create(['estacion_id' => $estacion->id])->id,
            'estacion_id' => $estacion->id,
            'fecha' => now()->subDay()->toDateString(),
            'fecha_fin' => $fin,
        ]);

        $vigente = $crear($this->estacion, now()->addDay()->toDateString());
        $sinFin = $crear($this->estacion, null);
        $crear($this->estacion, now()->subDay()->toDateString()); // ya terminó
        $crear(Estacion::factory()->create(), null); // otra estación

        $this->assertEqualsCanonicalizing(
            [$vigente->id, $sinFin->id],
            array_column($this->estado()['anterior']['fuera'], 'id'),
        );
    }

    public function test_los_visitantes_sugeridos_no_cruzan_de_estacion(): void
    {
        ComisionadoVisitante::factory()->create(['estacion_id' => $this->estacion->id, 'nombre' => 'Visita Propia', 'fecha' => now()->subDays(20)->toDateString()]);
        ComisionadoVisitante::factory()->create(['estacion_id' => Estacion::factory()->create()->id, 'nombre' => 'Visita Ajena', 'fecha' => now()->subDays(20)->toDateString()]);

        $this->assertSame(['Visita Propia'], array_column($this->estado()['sugerencias']['visitantes'], 'nombre'));
    }

    public function test_la_pantalla_muestra_pasos_y_vista_previa(): void
    {
        $this->empleado(['nombre_completo' => 'Persona De Pantalla']);

        $this->get(route('asistencia.captura.index'))
            ->assertOk()
            ->assertSee('Persona De Pantalla')
            ->assertSee('Vista previa del oficio')
            ->assertSee('¿Alguien de tu personal salió comisionado a otra área?');
    }
}
