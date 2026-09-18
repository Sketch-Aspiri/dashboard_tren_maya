<?php

namespace Tests\Feature\Documentos;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
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

        $this->assertSame(1, $data->resumen->de(EstatusAsistencia::Descanso));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::DiaNoLaborable));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::LicenciaMedica));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::Comision));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::AvisoIncidencia));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::ReporteAusentismo));
        $this->assertSame(1, $data->resumen->de(EstatusAsistencia::Vacaciones));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::Baja));
        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::NuevoIngreso));
        $this->assertSame(3, $data->resumen->de(EstatusAsistencia::Presente));
        $this->assertSame(5, $data->resumen->total());
    }

    public function test_lista_de_estacion_respeta_orden_oficio_y_arma_el_texto_de_estatus(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->escenarioPuertoMorelos($fecha);

        $filas = $this->builder()->paraEstacion($estacion, $fecha)->filas;

        $this->assertSame(
            ['Gerente Dummy', 'Enlace Dummy', 'Descansa Dummy', 'Taquillera Dummy', 'Auxiliar Eventual Dummy'],
            array_map(fn ($fila) => $fila->nombre, $filas),
        );
        $this->assertSame('VACACIONES (07 AL 19 DE SEPTIEMBRE)', $filas[0]->estatusTexto(enEstacion: true));
        $this->assertSame('PRESENTE EN ESTACIÓN PUERTO MORELOS', $filas[1]->estatusTexto(enEstacion: true));
        $this->assertSame('DESCANSO', $filas[2]->estatusTexto(enEstacion: true));
    }

    public function test_resumen_siempre_cuadra_con_las_filas_de_la_lista(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        foreach (EstatusAsistencia::cases() as $indice => $estatus) {
            $this->personal($estacion, 'Persona '.$indice, TipoPlaza::Permanente, $indice + 1, $estatus, $fecha);
        }

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertCount(10, $data->filas);
        $this->assertSame(10, $data->resumen->total());
        foreach (EstatusAsistencia::cases() as $estatus) {
            $this->assertSame(1, $data->resumen->de($estatus), $estatus->value);
        }
    }

    public function test_empleado_sin_registro_del_dia_se_considera_presente(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        Empleado::factory()->create(['estacion_id' => $estacion->id, 'nombre_completo' => 'Sin Registro']);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(1, $data->resumen->de(EstatusAsistencia::Presente));
        $this->assertSame(1, $data->resumen->total());
    }

    public function test_bajas_de_estacion_lista_solo_a_quienes_tienen_estatus_baja(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        $this->personal($estacion, 'Presente Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Presente, $fecha);
        $this->personal($estacion, 'Baja Dummy', TipoPlaza::Permanente, 2, EstatusAsistencia::Baja, $fecha);

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(['Baja Dummy'], array_map(fn ($fila) => $fila->nombre, $data->bajas()));
    }

    public function test_registros_de_otro_dia_no_cuentan(): void
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = $this->estacionPmo();
        $this->personal($estacion, 'Ayer Dummy', TipoPlaza::Permanente, 1, EstatusAsistencia::Descanso, $fecha->subDay());

        $data = $this->builder()->paraEstacion($estacion, $fecha);

        $this->assertSame(0, $data->resumen->de(EstatusAsistencia::Descanso));
        $this->assertSame(1, $data->resumen->de(EstatusAsistencia::Presente));
    }
}
