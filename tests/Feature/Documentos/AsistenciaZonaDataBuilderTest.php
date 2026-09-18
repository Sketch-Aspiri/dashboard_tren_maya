<?php

namespace Tests\Feature\Documentos;

use App\Enums\EmpleadoEstatus;
use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\ComisionadoFuera;
use App\Models\ComisionadoVisitante;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use App\Services\Documentos\AsistenciaDocumentoDataBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zone-wide oficio data, in miniature: mirrors the structure of the real
 * "asistencia de zona" Word (permanentes / eventuales / comisionados) with
 * dummy people.
 */
class AsistenciaZonaDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $fecha;

    private Estacion $pmo;

    private Estacion $eze;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fecha = CarbonImmutable::parse('2026-09-18');
        $this->pmo = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'is_operativa' => true]);
        $this->eze = Estacion::factory()->create(['nombre' => 'Edificio Zonal Este', 'is_operativa' => false]);
    }

    private function builder(): AsistenciaDocumentoDataBuilder
    {
        return new AsistenciaDocumentoDataBuilder(app(AsistenciaCapturaService::class));
    }

    private function persona(Estacion $estacion, string $nombre, TipoPlaza $tipo, int $orden, EstatusAsistencia $estatus = EstatusAsistencia::Presente, array $registro = []): Empleado
    {
        $empleado = Empleado::factory()->create([
            'estacion_id' => $estacion->id,
            'nombre_completo' => $nombre,
            'tipo_plaza' => $tipo->value,
            'orden_oficio' => $orden,
        ]);

        RegistroDiario::factory()->create(array_merge([
            'empleado_id' => $empleado->id,
            'estacion_id' => $estacion->id,
            'fecha' => $this->fecha->toDateString(),
            'estatus' => $estatus->value,
        ], $registro));

        return $empleado;
    }

    private function escenario(): void
    {
        $this->persona($this->pmo, 'Perm A', TipoPlaza::Permanente, 1);
        $this->persona($this->pmo, 'Perm B', TipoPlaza::Permanente, 2, EstatusAsistencia::Vacaciones, [
            'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-21',
        ]);
        $this->persona($this->eze, 'Perm C', TipoPlaza::Permanente, 3, EstatusAsistencia::Comision, [
            'notas' => 'Corp. Mérida', 'fecha_inicio' => '2026-09-16', 'fecha_fin' => '2026-09-17',
        ]);
        $comisionadoPerm = $this->persona($this->pmo, 'Perm D', TipoPlaza::Permanente, 4, EstatusAsistencia::Vacaciones, [
            'fecha_inicio' => '2026-09-14', 'fecha_fin' => '2026-09-21',
        ]);
        $this->persona($this->pmo, 'Event E', TipoPlaza::Eventual, 5);
        $comisionadoEv = $this->persona($this->pmo, 'Event F', TipoPlaza::Eventual, 6);

        ComisionadoFuera::factory()->create([
            'empleado_id' => $comisionadoPerm->id, 'estacion_id' => $this->pmo->id, 'fecha' => $this->fecha->toDateString(),
            'coordinacion_destino' => 'CGOFP', 'ubicacion_destino' => 'Edificio Tec. Ope. Cap. Cancún',
            'fecha_inicio' => null, 'fecha_fin' => null,
        ]);
        ComisionadoFuera::factory()->create([
            'empleado_id' => $comisionadoEv->id, 'estacion_id' => $this->pmo->id, 'fecha' => $this->fecha->toDateString(),
            'coordinacion_destino' => 'EZE', 'ubicacion_destino' => 'Bacalar',
            'fecha_inicio' => null, 'fecha_fin' => null,
        ]);
        ComisionadoVisitante::factory()->create([
            'estacion_id' => $this->pmo->id, 'fecha' => $this->fecha->toDateString(),
            'no_trabajador' => '251742', 'nombre' => 'Visitante Dummy', 'direccion_origen' => 'Dir. de Manto. de Eq. Ferrov.',
        ]);
    }

    public function test_listas_de_zona_excluyen_a_los_comisionados_fuera_y_respetan_el_orden(): void
    {
        $this->escenario();

        $data = $this->builder()->paraZona($this->fecha);

        $this->assertSame(['Perm A', 'Perm B', 'Perm C'], array_map(fn ($f) => $f->nombre, $data->permanentes));
        $this->assertSame(['Event E'], array_map(fn ($f) => $f->nombre, $data->eventuales));
        $this->assertSame([], $data->militares);
    }

    public function test_resumenes_de_zona_cuadran_con_sus_listas(): void
    {
        $this->escenario();

        $data = $this->builder()->paraZona($this->fecha);

        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Presente));
        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Vacaciones));
        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Comision));
        $this->assertSame(3, $data->resumenPermanentes->total());
        $this->assertSame(1, $data->resumenEventuales->de(EstatusAsistencia::Presente));
        $this->assertSame(1, $data->resumenEventuales->total());
        $this->assertSame(0, $data->resumenMilitares->total());
    }

    public function test_texto_de_estatus_de_zona_usa_notas_y_rango_corto(): void
    {
        $this->escenario();

        $data = $this->builder()->paraZona($this->fecha);

        $this->assertSame('PRESENTE', $data->permanentes[0]->estatusTextoZona());
        $this->assertSame('VACACIONES 14 AL 21 SEP. 2026', $data->permanentes[1]->estatusTextoZona());
        $this->assertSame('COMISIÓN CORP. MÉRIDA 16 AL 17 SEP. 2026', $data->permanentes[2]->estatusTextoZona());
        $this->assertSame('EDIFICIO ZONAL ESTE', $data->permanentes[2]->ubicacion);
    }

    public function test_seccion_de_comisionados(): void
    {
        $this->escenario();

        $data = $this->builder()->paraZona($this->fecha);

        $this->assertCount(1, $data->comisionadosAOtrasAreas);
        $fuera = $data->comisionadosAOtrasAreas[0];
        $this->assertSame('PERM D', $fuera->nombre);
        $this->assertSame('VACACIONES 14 AL 21 SEP. 2026 COMISIÓN CGOFP', $fuera->estatus);
        $this->assertSame('EDIFICIO TEC. OPE. CAP. CANCÚN', $fuera->ubicacion);

        $this->assertCount(1, $data->eventualesAOtrasAreas);
        $this->assertSame('EVENT F', $data->eventualesAOtrasAreas[0]->nombre);

        $this->assertCount(1, $data->comisionadosOtrasCoordinaciones);
        $visitante = $data->comisionadosOtrasCoordinaciones[0];
        $this->assertSame('VISITANTE DUMMY', $visitante->nombre);
        $this->assertSame('251742', $visitante->noEmpleado);
        $this->assertSame('DIR. DE MANTO. DE EQ. FERROV.', $visitante->direccion);
        $this->assertSame('PRESENTE', $visitante->estatus);
        $this->assertSame('ESTACIÓN PUERTO MORELOS', $visitante->ubicacion);
    }

    public function test_resumen_de_comisionados_cuenta_personas_distintas(): void
    {
        $this->escenario();

        $resumen = $this->builder()->paraZona($this->fecha)->resumenComisionados;

        $this->assertSame(1, $resumen->comisionPermanente);
        $this->assertSame(1, $resumen->comisionEventual);
        $this->assertSame(1, $resumen->comisionOtraCoordinacion);
        // Perm D is commissioned AND on vacation: counted in both columns, once in the total.
        $this->assertSame(1, $resumen->de(EstatusAsistencia::Vacaciones));
        $this->assertSame(3, $resumen->total);
    }

    public function test_personal_sin_estacion_o_inactivo_no_aparece_en_las_listas(): void
    {
        $this->escenario();
        Empleado::factory()->create(['estacion_id' => null, 'nombre_completo' => 'Sin Estacion', 'tipo_plaza' => TipoPlaza::Permanente->value]);
        Empleado::factory()->create([
            'estacion_id' => $this->pmo->id, 'nombre_completo' => 'Vacante', 'estatus' => EmpleadoEstatus::Vacante->value,
            'tipo_plaza' => TipoPlaza::Permanente->value,
        ]);

        $nombres = array_map(fn ($f) => $f->nombre, $this->builder()->paraZona($this->fecha)->permanentes);

        $this->assertNotContains('Sin Estacion', $nombres);
        $this->assertNotContains('Vacante', $nombres);
    }

    public function test_solo_cuentan_comisiones_de_la_fecha_pedida(): void
    {
        $this->escenario();
        ComisionadoFuera::query()->update(['fecha' => $this->fecha->subDay()->toDateString()]);

        $data = $this->builder()->paraZona($this->fecha);

        $this->assertSame([], $data->comisionadosAOtrasAreas);
        $this->assertCount(4, $data->permanentes); // Perm D is back in the list
    }
}
