<?php

namespace Tests\Feature\Documentos;

use App\Enums\EstatusAsistencia;
use App\Enums\TipoPlaza;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use App\Services\Documentos\AsistenciaDocumentoDataBuilder;
use App\Services\Documentos\GeneradorOficioAsistencia;
use App\Services\Documentos\ImportadorFirma;
use App\Services\Documentos\MetadatosOficio;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class GeneradorOficioAsistenciaTest extends TestCase
{
    use RefreshDatabase;

    private string $destino;

    /** @var list<string> */
    private array $temporales = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->destino = sys_get_temp_dir().DIRECTORY_SEPARATOR.'oficio_asistencia_'.uniqid().'.docx';
        $this->temporales[] = $this->destino;
    }

    protected function tearDown(): void
    {
        foreach ($this->temporales as $temporal) {
            if (is_file($temporal)) {
                unlink($temporal);
            }
        }

        parent::tearDown();
    }

    private function generarConEscenario(string $nombreExtra = 'Persona Normal', ?string $firmaPath = null, string $iniciales = 'BALS'): string
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

        (new GeneradorOficioAsistencia)->generar(
            $data,
            new MetadatosOficio('T.M.M./RR.HH./23PMO/00340/2026', 'Gerencia de Estación Puerto Morelos', 'Lic. Valentín Dummy', $iniciales, '', $firmaPath),
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

    private function entradasDelZip(string $ruta): array
    {
        $zip = new ZipArchive;
        $zip->open($ruta);
        $nombres = array_map(fn (int $i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        $zip->close();

        return $nombres;
    }

    public function test_docx_contiene_folio_fecha_lista_y_firma(): void
    {
        $texto = $this->generarConEscenario();

        $this->assertStringContainsString('Tjta. No. T.M.M./RR.HH./23PMO/00340/2026', $texto);
        $this->assertStringContainsString('CONTROL DE ASISTENCIA DIARIA', $texto);
        $this->assertStringContainsString('de la Estación Puerto Morelos del 18 Sep. 2026', $texto);
        $this->assertStringContainsString('me permito remitir', $texto);
        $this->assertStringContainsString('VALENTÍN DUMMY', $texto);
        $this->assertStringContainsString('VACACIONES 07 AL 19 SEP. 2026', $texto);
        $this->assertStringContainsString('Respetuosamente.', $texto);
        $this->assertStringContainsString('Gerencia de Estación Puerto Morelos', $texto);
        $this->assertStringContainsString('Lic. Valentín Dummy', $texto);
        $this->assertStringContainsString('BALS', $texto);
    }

    public function test_el_resumen_de_permanentes_coincide_con_los_datos(): void
    {
        $texto = $this->generarConEscenario();

        // descanso 1, dia no laborable 0, lic 0, comision 0, aviso 0, reporte 0,
        // vacaciones 1, bajas 0, nuevos 0, presentes 3, total 5 (data row of table b).
        $this->assertMatchesRegularExpression('/TOTAL PERMANENTES\s+1\s+0\s+0\s+0\s+0\s+0\s+1\s+0\s+0\s+3\s+5/', $texto);
    }

    public function test_incluye_las_secciones_a_a_e(): void
    {
        $texto = $this->generarConEscenario();

        foreach (['A.   CONTROL DE LICENCIAS', 'B. RELACIÓN DEL PERSONAL MILITAR', 'C. RELACIÓN DEL PERSONAL CIVIL', 'D. RELACIÓN DEL PERSONAL EVENTUAL', 'E. RELACIÓN DEL PERSONAL COMISIONADO', 'PERSONAL COMISIONADO A OTRAS ÁREAS'] as $titulo) {
            $this->assertStringContainsString($titulo, $texto);
        }
    }

    public function test_caracteres_especiales_en_nombres_no_rompen_el_docx(): void
    {
        $texto = $this->generarConEscenario('Nombre <Raro> & Cía');

        $this->assertStringContainsString('NOMBRE <RARO> & CÍA', $texto);
    }

    public function test_sin_firma_el_docx_solo_lleva_membrete_y_pie(): void
    {
        $this->generarConEscenario();

        $imagenes = array_filter($this->entradasDelZip($this->destino), fn (string $n) => str_starts_with($n, 'word/media/'));

        $this->assertCount(2, $imagenes);
    }

    public function test_con_firma_el_docx_incluye_la_imagen(): void
    {
        $firma = sys_get_temp_dir().DIRECTORY_SEPARATOR.'firma_'.uniqid().'.png';
        $this->temporales[] = $firma;
        file_put_contents($firma, UploadedFile::fake()->image('firma.png', 300, 100)->get());

        $this->generarConEscenario(firmaPath: $firma);

        $imagenes = array_filter($this->entradasDelZip($this->destino), fn (string $n) => str_starts_with($n, 'word/media/'));

        $this->assertCount(3, $imagenes);
    }

    public function test_el_importador_reescala_y_convierte_la_firma_a_png(): void
    {
        $destino = sys_get_temp_dir().DIRECTORY_SEPARATOR.'firma_'.uniqid().'.png';
        $this->temporales[] = $destino;

        (new ImportadorFirma)->aPng(UploadedFile::fake()->image('firma.jpg', 2000, 1000), $destino);

        [$ancho, $alto, $tipo] = getimagesize($destino);
        $this->assertSame(IMAGETYPE_PNG, $tipo);
        $this->assertSame(900, $ancho);
        $this->assertSame(450, $alto);
    }

    public function test_el_importador_rechaza_archivos_que_no_son_imagen(): void
    {
        $this->expectException(DomainException::class);

        (new ImportadorFirma)->aPng(UploadedFile::fake()->createWithContent('firma.png', 'no soy una imagen'), sys_get_temp_dir().DIRECTORY_SEPARATOR.'x.png');
    }
}
