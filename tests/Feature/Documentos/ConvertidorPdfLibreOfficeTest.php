<?php

namespace Tests\Feature\Documentos;

use App\Services\AsistenciaDocumentoService;
use App\Services\Documentos\ConversionPdfException;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\ConvertidorPdfLibreOffice;
use App\Services\Documentos\DirectorioTemporal;
use Tests\TestCase;

class ConvertidorPdfLibreOfficeTest extends TestCase
{
    private string $pdf;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdf = sys_get_temp_dir().DIRECTORY_SEPARATOR.'convertidor_'.uniqid().'.pdf';
    }

    protected function tearDown(): void
    {
        if (is_file($this->pdf)) {
            unlink($this->pdf);
        }

        parent::tearDown();
    }

    private function localizarSoffice(): ?string
    {
        $candidatos = array_filter([
            getenv('ASISTENCIA_SOFFICE_PATH') ?: null,
            'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
            '/usr/bin/soffice',
            '/usr/local/bin/soffice',
        ]);

        foreach ($candidatos as $ruta) {
            if (is_file($ruta)) {
                return $ruta;
            }
        }

        return null;
    }

    public function test_convierte_un_docx_a_pdf_valido(): void
    {
        $soffice = $this->localizarSoffice();
        if ($soffice === null) {
            $this->markTestSkipped('LibreOffice no está instalado en este entorno.');
        }

        (new ConvertidorPdfLibreOffice($soffice, 90))->convertir(resource_path('documentos/asistencia_estacion.docx'), $this->pdf);

        $this->assertFileExists($this->pdf);
        $this->assertStringStartsWith('%PDF', (string) file_get_contents($this->pdf, false, null, 0, 4));
    }

    public function test_el_contenedor_resuelve_el_servicio_con_el_convertidor_real(): void
    {
        // No fake here on purpose: the other feature tests swap the converter out,
        // which would hide a real binding that does not satisfy the interface.
        $this->assertInstanceOf(ConvertidorPdfLibreOffice::class, app(ConvertidorPdf::class));
        $this->assertInstanceOf(AsistenciaDocumentoService::class, app(AsistenciaDocumentoService::class));
    }

    public function test_falla_con_excepcion_controlada_si_el_binario_no_existe(): void
    {
        $this->expectException(ConversionPdfException::class);

        (new ConvertidorPdfLibreOffice('/ruta/que/no/existe/soffice', 10))
            ->convertir(resource_path('documentos/asistencia_estacion.docx'), $this->pdf);
    }

    public function test_no_deja_carpetas_temporales_tras_convertir(): void
    {
        $soffice = $this->localizarSoffice();
        if ($soffice === null) {
            $this->markTestSkipped('LibreOffice no está instalado en este entorno.');
        }

        // The converter names its work folders lo_<uuid>; match only that shape
        // so unrelated "lo_*" folders in the temp dir never affect the count.
        $patron = DirectorioTemporal::ruta().DIRECTORY_SEPARATOR.'lo_*-*-*-*-*';
        $antes = glob($patron) ?: [];

        (new ConvertidorPdfLibreOffice($soffice, 90))->convertir(resource_path('documentos/asistencia_estacion.docx'), $this->pdf);

        $despues = glob($patron) ?: [];
        $this->assertSame(count($antes), count($despues));
    }
}
