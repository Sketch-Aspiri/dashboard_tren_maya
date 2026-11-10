<?php

namespace Tests\Feature;

use App\Jobs\VerificarFacturaSatJob;
use App\Models\Factura;
use App\Models\User;
use App\Services\ComprobacionPdfService;
use App\Services\ComprobacionService;
use App\Services\Sat\ConsultaSatException;
use App\Services\Sat\ConsultorSat;
use App\Services\Sat\ResultadoSat;
use App\Services\VerificacionSatService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Consulta del estado de las facturas en el SAT: traducción de la respuesta,
 * hallazgos, caída del servicio (no rompe la carga), consulta automática en
 * cola, comando diario, botón manual y bloqueo de la comprobación en PDF de
 * una factura cancelada. El SAT se sustituye por un doble: nada sale a la red.
 */
class VerificacionSatTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    /** @var list<string> */
    public array $consultas = [];

    private ResultadoSat|ConsultaSatException $respuesta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->respuesta = new ResultadoSat(ResultadoSat::VIGENTE, 'Cancelable con aceptación', 'En proceso');

        $prueba = $this;
        $this->app->instance(ConsultorSat::class, new class($prueba) implements ConsultorSat
        {
            public function __construct(private readonly VerificacionSatTest $prueba) {}

            public function consultar(string $xml): ResultadoSat
            {
                $this->prueba->consultas[] = $xml;
                $respuesta = $this->prueba->responder();

                return $respuesta instanceof ConsultaSatException ? throw $respuesta : $respuesta;
            }
        });
    }

    public function responder(): ResultadoSat|ConsultaSatException
    {
        return $this->respuesta;
    }

    private function jefe(): User
    {
        $usuario = User::factory()->create();
        $usuario->assignRole('Jefe de Zona');

        return $usuario;
    }

    private function factura(array $atributos = []): Factura
    {
        Storage::disk('local')->put('facturas/xml/a.xml', '<cfdi/>');
        Storage::disk('local')->put('facturas/pdf/a.pdf', '%PDF-1.4');

        return Factura::query()->create($atributos + [
            'anio' => 2026, 'mes' => 10, 'uuid' => '949E7F59-1302-46BF-A27C-D502299B7CA5', 'folio' => '1', 'fecha' => '2026-10-05',
            'emisor_rfc' => 'GFC051130D29', 'emisor_nombre' => 'X', 'receptor_rfc' => 'TMA220603D80', 'tipo_comprobante' => 'I', 'moneda' => 'MXN',
            'subtotal' => 100, 'iva' => 8, 'total' => 108, 'estado' => Factura::OK, 'xml_ruta' => 'facturas/xml/a.xml', 'pdf_ruta' => 'facturas/pdf/a.pdf',
        ]);
    }

    /**
     * @return list<string> "TIPO:CODIGO" de cada hallazgo
     */
    private function hallazgos(Factura $factura): array
    {
        return $factura->hallazgos()->get()->map(fn ($h) => $h->tipo.':'.$h->codigo)->all();
    }

    public function test_vigente_se_guarda_con_detalle_y_fecha(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());
        $factura = $this->factura();

        $this->post(route('insumos.facturas.verificar-sat', $factura))->assertRedirect(route('insumos.facturas.show', $factura));

        $factura->refresh();
        $this->assertSame('VIGENTE', $factura->verificacion_sat);
        $this->assertSame('Cancelable con aceptación', $factura->sat_es_cancelable);
        $this->assertNotNull($factura->sat_consultado_at);
        $this->assertSame(['<cfdi/>'], $this->consultas);
        $this->assertNotContains('ERROR:CANCELADA_SAT', $this->hallazgos($factura));
    }

    public function test_cancelada_marca_error_y_bloquea_la_comprobacion_en_pdf(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());
        $factura = $this->factura();
        $this->respuesta = new ResultadoSat(ResultadoSat::CANCELADO);

        $this->post(route('insumos.facturas.verificar-sat', $factura));

        $factura->refresh();
        $this->assertSame('CANCELADO', $factura->verificacion_sat);
        $this->assertSame(Factura::ERROR, $factura->estado);
        $this->assertContains('ERROR:CANCELADA_SAT', $this->hallazgos($factura));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CANCELADA');
        app(ComprobacionPdfService::class)->generar(collect([$factura]));
    }

    public function test_no_encontrada_pide_revisar(): void
    {
        $factura = $this->factura();
        $this->respuesta = new ResultadoSat(ResultadoSat::NO_ENCONTRADO);

        app(VerificacionSatService::class)->verificar($factura);

        $this->assertContains('REVISAR:NO_ENCONTRADA_SAT', $this->hallazgos($factura->refresh()));
    }

    public function test_si_el_sat_no_responde_queda_en_error_con_alerta_y_sin_romper(): void
    {
        $factura = $this->factura();
        $this->respuesta = new ConsultaSatException('El SAT no respondió: timeout');

        $estado = app(VerificacionSatService::class)->verificar($factura);

        $factura->refresh();
        $this->assertSame('ERROR', $estado);
        $this->assertStringContainsString('timeout', (string) $factura->sat_detalle);
        $this->assertContains('ALERTA:SAT_NO_DISPONIBLE', $this->hallazgos($factura));
    }

    public function test_sin_xml_guardado_queda_en_error(): void
    {
        $factura = $this->factura(['xml_ruta' => 'facturas/xml/no-existe.xml']);

        $this->assertSame('ERROR', app(VerificacionSatService::class)->verificar($factura));
        $this->assertSame([], $this->consultas);
    }

    public function test_al_cargar_se_encola_la_consulta_si_esta_activa(): void
    {
        Bus::fake();
        config(['insumos.sat.consulta_automatica' => true]);
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->postXml();

        Bus::assertDispatched(VerificarFacturaSatJob::class);
    }

    public function test_al_cargar_no_se_encola_si_esta_apagada(): void
    {
        Bus::fake();
        $this->actingAsTwoFactorVerified($this->jefe());

        $this->postXml();

        Bus::assertNotDispatched(VerificarFacturaSatJob::class);
    }

    private function postXml(): void
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="4.0" Folio="9" Fecha="2026-10-05T00:00:00" SubTotal="100" Moneda="MXN" Total="108" TipoDeComprobante="I">'
            .'<cfdi:Emisor Rfc="GFC051130D29" Nombre="X" RegimenFiscal="601"/><cfdi:Receptor Rfc="TMA220603D80" Nombre="TREN MAYA" UsoCFDI="G03"/>'
            .'<cfdi:Conceptos><cfdi:Concepto ClaveProdServ="40141700" Cantidad="1" ClaveUnidad="H87" Descripcion="CABLE" ValorUnitario="100" Importe="100" ObjetoImp="02"/></cfdi:Conceptos>'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital Version="1.1" UUID="AAAAAAAA-0000-4000-8000-000000000009" FechaTimbrado="2026-10-05T10:00:00"/></cfdi:Complemento></cfdi:Comprobante>';

        $this->post(route('insumos.facturas.store'), [
            'anio' => 2026, 'mes' => 10,
            'xml' => [UploadedFile::fake()->createWithContent('f.xml', $xml)],
            'pdf' => [UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n")],
        ])->assertSessionHasNoErrors();
    }

    public function test_comando_reconsulta_vigentes_con_error_y_sin_consultar_pero_no_canceladas(): void
    {
        $vigente = $this->factura(['verificacion_sat' => 'VIGENTE', 'uuid' => 'AAAAAAAA-0000-4000-8000-000000000001']);
        $error = $this->factura(['verificacion_sat' => 'ERROR', 'uuid' => 'AAAAAAAA-0000-4000-8000-000000000002']);
        $nueva = $this->factura(['uuid' => 'AAAAAAAA-0000-4000-8000-000000000003']);
        $cancelada = $this->factura(['verificacion_sat' => 'CANCELADO', 'uuid' => 'AAAAAAAA-0000-4000-8000-000000000004']);

        $this->artisan('facturas:verificar-sat')->assertSuccessful();

        $this->assertCount(3, $this->consultas);
        $this->assertNotNull($vigente->refresh()->sat_consultado_at);
        $this->assertNotNull($error->refresh()->sat_consultado_at);
        $this->assertNotNull($nueva->refresh()->sat_consultado_at);
        $this->assertNull($cancelada->refresh()->sat_consultado_at);
    }

    public function test_el_boton_requiere_rol_y_sesion(): void
    {
        $factura = $this->factura();

        $this->post(route('insumos.facturas.verificar-sat', $factura))->assertRedirect(route('login'));

        $usuario = User::factory()->create();
        $usuario->assignRole('Estación');
        $this->actingAsTwoFactorVerified($usuario);
        $this->post(route('insumos.facturas.verificar-sat', $factura))->assertForbidden();
        $this->assertSame([], $this->consultas);
    }

    public function test_el_resumen_del_mes_refleja_el_peor_estado_del_sat(): void
    {
        $this->factura(['verificacion_sat' => 'VIGENTE', 'uuid' => 'AAAAAAAA-0000-4000-8000-000000000001']);
        $comprobacion = app(ComprobacionService::class);
        $this->assertSame('VIGENTE', $comprobacion->resumen(2026, 10)['sat']);

        $this->factura(['verificacion_sat' => 'CANCELADO', 'uuid' => 'AAAAAAAA-0000-4000-8000-000000000002']);
        $this->assertSame('CANCELADO', $comprobacion->resumen(2026, 10)['sat']);
        $this->assertSame('NO_VERIFICADO', $comprobacion->resumen(2026, 11)['sat']);
    }

    public function test_la_ficha_muestra_el_estado_y_el_boton(): void
    {
        $this->actingAsTwoFactorVerified($this->jefe());
        $factura = $this->factura(['verificacion_sat' => 'CANCELADO', 'sat_consultado_at' => now()]);

        $this->get(route('insumos.facturas.show', $factura))->assertOk()->assertSee('CANCELADO')->assertSee('Verificar ahora');
    }
}
