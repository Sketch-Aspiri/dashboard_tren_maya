<?php

namespace Tests\Feature;

use App\Models\Estacion;
use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\ComprobacionPdfService;
use App\Services\Documentos\ConvertidorPdf;
use App\Services\Documentos\UnidorPdf;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;
use ZipArchive;

/**
 * PDF de comprobación de facturas: texto de la hoja de validación, orden de
 * las páginas (factura, validación, factura, validación XML), vista previa y
 * permisos. LibreOffice y Ghostscript se sustituyen por dobles.
 */
class ComprobacionPdfTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    /** @var list<list<string>> */
    public array $unidos = [];

    /** @var list<array{xml: string, imagenes: int}> */
    public array $documentos = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');

        $prueba = $this;
        $this->app->instance(ConvertidorPdf::class, new class($prueba) implements ConvertidorPdf
        {
            public function __construct(private readonly ComprobacionPdfTest $prueba) {}

            public function convertir(string $docx, string $pdfDestino): void
            {
                $zip = new ZipArchive;
                $zip->open($docx);
                $nombres = array_map(fn (int $i) => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
                $this->prueba->documentos[] = [
                    'xml' => (string) $zip->getFromName('word/document.xml'),
                    'imagenes' => count(array_filter($nombres, fn (string $n) => str_starts_with($n, 'word/media/'))),
                ];
                $zip->close();
                file_put_contents($pdfDestino, '%PDF-hoja '.md5((string) file_get_contents($docx)));
            }
        });

        $this->app->instance(UnidorPdf::class, new class($prueba) implements UnidorPdf
        {
            public function __construct(private readonly ComprobacionPdfTest $prueba) {}

            public function unir(array $origenes, string $destino): void
            {
                $this->prueba->unidos[] = array_map(fn (string $ruta) => basename($ruta), $origenes);
                file_put_contents($destino, '%PDF-unido');
            }
        });
    }

    private function usuario(string $rol): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole($rol);

        return $usuario;
    }

    private function factura(array $estaciones = ['Tulum', 'Chetumal'], bool $conPdf = true, float $total = 24872.75): Factura
    {
        $factura = Factura::query()->create([
            'anio' => 2026, 'mes' => 10, 'uuid' => '949E7F59-1302-46BF-A27C-D502299B7CA5', 'folio' => '153', 'fecha' => '2026-10-05',
            'emisor_rfc' => 'GFC051130D29', 'emisor_nombre' => 'GRUPO FERRETERO CONTINENTAL', 'receptor_rfc' => 'TMA220603D80',
            'tipo_comprobante' => 'I', 'moneda' => 'MXN', 'subtotal' => $total, 'iva' => 0, 'total' => $total, 'estado' => Factura::OK,
        ]);

        if ($conPdf) {
            Storage::disk('local')->put('facturas/pdf/f.pdf', '%PDF-factura');
            $factura->update(['pdf_ruta' => 'facturas/pdf/f.pdf', 'pdf_nombre' => 'f.pdf']);
        }

        foreach ($estaciones as $i => $nombre) {
            $estacion = Estacion::factory()->create(['nombre' => $nombre, 'orden' => $i + 1]);
            $solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $estacion->id, 'anio' => 2026, 'mes' => 10, 'estado' => SolicitudInsumo::APROBADA]);
            $partida = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => $solicitud->id, 'partida_presupuestal' => '24901', 'numero' => 1]);
            FacturaConcepto::query()->create([
                'factura_id' => $factura->id, 'numero' => $i + 1, 'descripcion' => 'X', 'cantidad' => 1, 'valor_unitario' => 1, 'importe' => 1,
                'solicitud_insumo_partida_id' => $partida->id,
            ]);
        }

        return $factura->refresh();
    }

    public function test_texto_replica_la_plantilla_con_estaciones_importe_y_partida(): void
    {
        $factura = $this->factura(['Tulum', 'Chetumal', 'Nicolás Bravo']);
        $servicio = app(ComprobacionPdfService::class);

        $texto = $servicio->texto($factura, ComprobacionPdfService::FACTURA);

        $this->assertStringStartsWith('La presente factura con folio fiscal 949E7F59-1302-46BF-A27C-D502299B7CA5 ampara la compra de insumos', $texto);
        $this->assertStringContainsString('las estaciones de Tulum, Chetumal y Nicolás Bravo, pertenecientes a la Zona Oriente', $texto);
        $this->assertStringContainsString('por un importe de $ 24,872.75 (Veinticuatro mil ochocientos setenta y dos pesos 75/100 M.N.)', $texto);
        $this->assertStringContainsString('afectando la partida presupuestal 24901 "Otros materiales y artículos de construcción y reparación"', $texto);
        $this->assertStringStartsWith('El presente XML con folio fiscal', $servicio->texto($factura, ComprobacionPdfService::XML));
    }

    public function test_con_muchas_estaciones_usa_la_formula_general(): void
    {
        $factura = $this->factura(['A', 'B', 'C', 'D', 'E']);

        $this->assertStringContainsString('necesidades de las estaciones pertenecientes a la Zona Oriente, por un importe', app(ComprobacionPdfService::class)->texto($factura, 'FACTURA'));
    }

    public function test_el_pdf_lleva_factura_validacion_factura_validacion_xml(): void
    {
        $factura = $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('insumos.facturas.comprobacion', $factura))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="COMPROBACION_153.pdf"');

        $this->assertCount(1, $this->unidos);
        $this->assertSame('f.pdf', $this->unidos[0][0]);
        $this->assertSame('f.pdf', $this->unidos[0][2]);
        $this->assertStringContainsString('_FACTURA_', $this->unidos[0][1]);
        $this->assertStringContainsString('_XML_', $this->unidos[0][3]);
    }

    public function test_ver_la_abre_en_el_navegador_y_la_hoja_se_reutiliza(): void
    {
        $factura = $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('insumos.facturas.comprobacion', [$factura, 'ver' => 1]))->assertHeader('Content-Disposition', 'inline; filename="COMPROBACION_153.pdf"');
        $primeras = $this->unidos[0];
        $this->get(route('insumos.facturas.comprobacion', $factura))->assertOk();

        $this->assertSame($primeras, $this->unidos[1]);
    }

    public function test_sin_pdf_de_la_factura_avisa_y_no_genera(): void
    {
        $factura = $this->factura(conPdf: false);
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->from(route('insumos.facturas.show', $factura))->get(route('insumos.facturas.comprobacion', $factura))
            ->assertRedirect(route('insumos.facturas.show', $factura))->assertSessionHasErrors('comprobacion');
        $this->assertSame([], $this->unidos);
    }

    public function test_comprobacion_del_mes_junta_todas_las_facturas(): void
    {
        $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Administrador'));

        $this->get(route('insumos.facturas.comprobacion.mes', ['anio' => 2026, 'mes' => 10]))->assertOk();
        $this->assertCount(4, $this->unidos[0]);

        $this->get(route('insumos.facturas.comprobacion.mes', ['anio' => 2026, 'mes' => 11]))->assertSessionHasErrors('comprobacion');
    }

    public function test_cargos_nombres_y_firma_se_aplican_a_las_hojas_y_la_firma_no_se_guarda(): void
    {
        $factura = $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('insumos.facturas.comprobacion', $factura), ['firmantes' => [
            'recibio' => ['cargo' => 'La Subgerente de Zona', 'nombre' => 'Ing. Ana Pérez & Hijos', 'firma' => UploadedFile::fake()->image('firma.png', 200, 80)],
            'autorizo' => ['cargo' => '', 'nombre' => ''],
        ]])->assertOk();

        $this->assertCount(2, $this->documentos);
        $this->assertStringContainsString('La Subgerente de Zona', $this->documentos[0]['xml']);
        $this->assertStringContainsString('Ing. Ana Pérez &amp; Hijos', $this->documentos[0]['xml']);
        $this->assertStringContainsString('Lic. Francisco Javier Moreno Carrillo', $this->documentos[0]['xml']);
        $this->assertSame(1, $this->documentos[0]['imagenes']);
        $this->assertSame([], Storage::disk('local')->files('facturas/validacion'));
    }

    public function test_firmantes_invalidos_se_rechazan(): void
    {
        $factura = $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->post(route('insumos.facturas.comprobacion', $factura), ['firmantes' => ['recibio' => ['nombre' => '${TEXTO}', 'firma' => UploadedFile::fake()->create('f.exe', 5)]]])
            ->assertInvalid(['firmantes.recibio.nombre', 'firmantes.recibio.firma']);
        $this->assertSame([], $this->unidos);
    }

    public function test_la_ficha_muestra_la_vista_previa_con_las_dos_hojas(): void
    {
        $factura = $this->factura();
        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));

        $this->get(route('insumos.facturas.show', $factura))->assertOk()
            ->assertSee('Comprobación de la factura (vista previa)')
            ->assertSee('VALIDAN LA PRESENTE FACTURA:')->assertSee('VALIDAN EL PRESENTE XML:')
            ->assertSee('Libramiento de Chetumal km 15')
            ->assertSee('firmantes[recibio][cargo]', false)->assertSee('firmantes[autorizo][firma]', false);
    }

    public function test_las_estaciones_no_pueden_generar_la_comprobacion(): void
    {
        $factura = $this->factura();
        $estacion = Estacion::query()->firstOrFail();
        $this->actingAsTwoFactorVerified($this->usuario('Estación')->forceFill(['estacion_id' => $estacion->id]));

        $this->get(route('insumos.facturas.comprobacion', $factura))->assertForbidden();
        $this->get(route('insumos.facturas.comprobacion.mes'))->assertForbidden();
    }
}
