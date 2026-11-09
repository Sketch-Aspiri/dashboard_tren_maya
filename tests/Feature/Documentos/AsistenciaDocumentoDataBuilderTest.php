<?php

namespace Tests\Feature\Documentos;

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
 * Golden test for the "oficio de estación" data: mirrors the real Puerto
 * Morelos example (23_PMO_18_SEPTIEMBRE_CONTROL DE ASISTENCIA.pdf) using
 * dummy names and numbers — only the shape (5 people, 1 descanso,
 * 1 vacaciones, 3 presentes, a mixed permanente/eventual list) is real.
 */
class AsistenciaDocumentoDataBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function builder(): AsistenciaDocumentoDataBuilder
    {
        return new AsistenciaDocumentoDataBuilder(app(AsistenciaCapturaService::class));
    }

    private function estacionPmo(): Estacion
    {
        return Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'is_operativa' => true]);
    }

    private function personal(Estacion $estacion, string $nombre, TipoPlaza $tipo, int $orden, EstatusAsistencia $estatus, CarbonImmutable $fecha, array $registro = []): Empleado
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
            'fecha' => $fecha->toDateString(),
            'estatus' => $estatus->value,
        ], $registro));

        return $empleado;
    }

    private function escenarioPuertoMorelos(CarbonImmutable $fecha): Estacion
    {
        $estacion = $this->estacionPmo();

        $this->personal($estacion, 'Gerente Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Vacaciones, $fecha, [
            'fecha_inicio' => '2026-09-07',
            'fecha_fin' => '2026-09-19',
        ]);
        $this->personal($estacion, 'Enlace Dummy', TipoPlaza::Permanente, 2, EstatusAsistencia::Presente, $fecha);
        $this->personal($estacion, 'Descansa Dummy', TipoPlaza::Permanente, 3, EstatusAsistencia::Descanso, $fecha);
        $this->personal($estacion, 'Taquillera Dummy', TipoPlaza::Permanente, 4, EstatusAsistencia::Presente, $fecha);
        $this->personal($estacion, 'Auxiliar Eventual Dummy', TipoPlaza::Eventual, 5, EstatusAsistencia::Presente, $fecha);

        return $estacion;
    }

    public function test_resumen_de_estacion_coincide_con_el_ejemplo_real(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->escenarioPuertoMorelos($fecha);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $permanentes = $data->resumenPermanentes;
        $this->assertSame(1, $permanentes->de(EstatusAsistencia::Descanso));
        $this->assertSame(0, $permanentes->de(EstatusAsistencia::DiaNoLaborable));
        $this->assertSame(0, $permanentes->de(EstatusAsistencia::LicenciaMedica));
        $this->assertSame(0, $permanentes->de(EstatusAsistencia::Comision));
        $this->assertSame(1, $permanentes->de(EstatusAsistencia::Vacaciones));
        $this->assertSame(2, $permanentes->de(EstatusAsistencia::Presente));
        $this->assertSame(4, $permanentes->total());
        $this->assertSame(1, $data->resumenEventuales->de(EstatusAsistencia::Presente));
        $this->assertSame(1, $data->resumenEventuales->total());
        $this->assertSame(0, $data->resumenMilitares->total());
        $this->assertSame($data->estacion?->id, $data->estacion->id);
    }

    public function test_lista_de_estacion_respeta_orden_oficio_y_arma_el_texto_de_estatus(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->escenarioPuertoMorelos($fecha);

        $data = $this->builder()->paraEstacion($estacion, $fecha);
        $filas = $data->permanentes;

        $this->assertSame(
            ['Gerente Dummy', 'Enlace Dummy', 'Descansa Dummy', 'Taquillera Dummy'],
            array_map(fn ($fila) => $fila->nombre, $filas),
        );
        $this->assertSame(['Auxiliar Eventual Dummy'], array_map(fn ($fila) => $fila->nombre, $data->eventuales));
        $this->assertSame(['VACACIONES', '07 AL 19 SEP. 2026'], $filas[0]->estatusLineas());
        $this->assertSame(['PRESENTE'], $filas[1]->estatusLineas());
        $this->assertSame(['DESCANSO'], $filas[2]->estatusLineas());
    }

    public function test_resumen_siempre_cuadra_con_las_filas_de_la_lista(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        foreach (EstatusAsistencia::cases() as $indice => $estatus) {
            $this->personal($estacion, 'Persona '.$indice, TipoPlaza::Permanente, $indice + 1, $estatus, $fecha);
        }

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertCount(10, $data->permanentes);
        $this->assertSame(10, $data->resumenPermanentes->total());
        foreach (EstatusAsistencia::cases() as $estatus) {
            $this->assertSame(1, $data->resumenPermanentes->de($estatus), $estatus->value);
        }
    }

    public function test_empleado_sin_registro_del_dia_se_considera_presente(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        Empleado::factory()->create(['estacion_id' => $estacion->id, 'nombre_completo' => 'Sin Registro', 'tipo_plaza' => TipoPlaza::Permanente->value]);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Presente));
        $this->assertSame(1, $data->resumenPermanentes->total());
    }

    public function test_las_bajas_se_cuentan_en_el_resumen_y_siguen_en_la_lista(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        $this->personal($estacion, 'Presente Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Presente, $fecha);
        $this->personal($estacion, 'Baja Dummy', TipoPlaza::Permanente, 2, EstatusAsistencia::Baja, $fecha);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Baja));
        $this->assertSame(['Presente Dummy', 'Baja Dummy'], array_map(fn ($fila) => $fila->nombre, $data->permanentes));
    }

    public function test_registros_de_otro_dia_no_cuentan(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        $this->personal($estacion, 'Ayer Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Descanso, $fecha->subDay());

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(0, $data->resumenPermanentes->de(EstatusAsistencia::Descanso));
        $this->assertSame(1, $data->resumenPermanentes->de(EstatusAsistencia::Presente));
    }

    public function test_el_oficio_de_estacion_solo_incluye_comisionados_de_esa_estacion(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        $otra = Estacion::factory()->create(['nombre' => 'Tulum', 'is_operativa' => true]);

        $propio = $this->personal($estacion, 'Propio Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Presente, $fecha);
        $ajeno = $this->personal($otra, 'Ajeno Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Presente, $fecha);

        foreach ([$propio, $ajeno] as $empleado) {
            ComisionadoFuera::factory()->create([
                'empleado_id' => $empleado->id,
                'estacion_id' => $empleado->estacion_id,
                'fecha' => $fecha->toDateString(),
            ]);
        }
        ComisionadoVisitante::factory()->create(['estacion_id' => $estacion->id, 'fecha' => $fecha->toDateString(), 'nombre' => 'Visita Pmo']);
        ComisionadoVisitante::factory()->create(['estacion_id' => $otra->id, 'fecha' => $fecha->toDateString(), 'nombre' => 'Visita Tulum']);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame([], $data->permanentes); // the commissioned person is listed only in section E
        $this->assertSame(['PROPIO DUMMY'], array_map(fn ($f) => $f->nombre, $data->comisionadosAOtrasAreas));
        $this->assertSame(['VISITA PMO'], array_map(fn ($f) => $f->nombre, $data->comisionadosOtrasCoordinaciones));
    }
}
