<?php

namespace Tests\Feature\Documentos;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use App\Services\Documentos\AsistenciaDocumentoDataBuilder;
use App\Services\Documentos\GeneradorOficioEstacion;
use App\Services\Documentos\MetadatosOficio;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class GeneradorOficioEstacionTest extends TestCase
{
    use RefreshDatabase;

    private string $destino;

    protected function setUp(): void
    {
        parent::setUp();

        $this->destino = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oficio_estacion_'.uniqid().'.docx';
    }

    protected function tearDown(): void
    {
        if (is_file($this->destino)) {
            unlink($this->destino);
        }

        parent::tearDown();
    }

    private function generarConEscenario(string $nombreExtra = 'Persona Normal'): string
    {
        $fecha = CarbonImmutable::parse('2026-09-18');
        $estacion = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'is_operativa' => true]);

        $alta = fn (string $nombre, int $orden, EstatusAsistencia $estatus, array $registro = []) => RegistroDiario::factory()->create(array_merge([
            'empleado_id' => Empleado::factory()->create([
                'estacion_id' => $estacion->id,
                'nombre_completo' => $nombre,
                'no_empleado' => (string) (240000 + $orden),
                'tipo_plaza' => TipoPlaza::Permanente->value,
                'orden_oficio' => $orden,
            ])->id,
            'estacion_id' => $estacion->id,
            'fecha' => $fecha->toDateString(),
            'estatus' => $estatus->value,
        ], $registro));

        $alta('Valentín Dummy', 1, EstatusAsistencia::Vacaciones, ['fecha_inicio' => '2026-09-07', 'fecha_fin' => '2026-09-19']);
        $alta($nombreExtra, 2, EstatusAsistencia::Presente);
        $alta('Descansa Dummy', 3, EstatusAsistencia::Descanso);
        $alta('Presente Tres', 4, EstatusAsistencia::Presente);
        $alta('Presente Cuatro', 5, EstatusAsistencia::Presente);

        $data = (new AsistenciaDocumentoDataBuilder(app(AsistenciaCapturaService::class)))->paraEstacion($estacion, $fecha);

        (new GeneradorOficioEstacion)->generar(
            $data,
            new MetadatosOficio('T.M.M./RR.HH./23PMO/00340/2026', 'Gerente de Estación Puerto Morelos', 'Lic. Valentín Dummy'),
            $this->destino,
        );

        return $this->textoDelDocx($this->destino);
    }

    private function textoDelDocx(string $ruta): string
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta) === true, 'El .docx generado no es un zip válido');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        // Cell/paragraph boundaries become spaces so assertions can match phrases.
        return html_entity_decode(strip_tags(str_replace(['</w:p>', '</w:tc>'], ' ', $xml)), ENT_QUOTES | ENT_XML1);
    }

    public function test_docx_contiene_folio_fecha_lista_y_firma(): void
    {
        $texto = $this->generarConEscenario();

        $this->assertStringContainsString('Tjta. No. T.M.M./RR.HH./23PMO/00340/2026', $texto);
        $this->assertStringContainsString('(18 septiembre 2026)', $texto);
        $this->assertStringContainsString('Milanez Navarrete', $texto);
        $this->assertStringContainsString('VALENTÍN DUMMY', $texto);
        $this->assertStringContainsString('VACACIONES (07 AL 19 DE SEPTIEMBRE)', $texto);
        $this->assertStringContainsString('PRESENTE EN ESTACIÓN PUERTO MORELOS', $texto);
        $this->assertStringContainsString('GERENTE DE ESTACIÓN PUERTO MORELOS', $texto);
        $this->assertStringContainsString('Lic. Valentín Dummy', $texto);
    }

    public function test_no_quedan_marcadores_sin_reemplazar(): void
    {
        $texto = $this->generarConEscenario();

        $this->assertStringNotContainsString('${', $texto);
    }

    public function test_fila_de_resumen_coincide_con_los_datos(): void
    {
        $texto = $this->generarConEscenario();

        // descanso 1, dia no laborable 0, lic 0, comision 0, aviso 0, reporte 0,
        // vacaciones 1, bajas 0, nuevos 0, presentes 3, totales 5 (data row of table A).
        $this->assertMatchesRegularExpression(
            '/COORD\. GRAL\. DE GEST\. DE INFRA\. FERROVIARIA\s+1\s+0\s+0\s+0\s+0\s+0\s+1\s+0\s+0\s+3\s+5/',
            $texto,
        );
    }

    public function test_caracteres_especiales_en_nombres_no_rompen_el_docx(): void
    {
        $texto = $this->generarConEscenario('Nombre <Raro> & Cía');

        $this->assertStringContainsString('NOMBRE <RARO> & CÍA', $texto);
    }

    public function test_seccion_de_bajas_queda_en_blanco_sin_marcadores_cuando_no_hay_bajas(): void
    {
        $texto = $this->generarConEscenario();

        $this->assertStringContainsString('E. RELACIÓN DEL PERSONAL CON BAJA O PENDIENTE DE BAJA', $texto);
        $this->assertStringNotContainsString('${e_', $texto);
    }
}
