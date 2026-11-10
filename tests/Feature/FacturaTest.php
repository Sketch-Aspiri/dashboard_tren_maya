<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\CotizacionRenglon;
use App\Models\Estacion;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\CfdiParser;
use App\Services\ComprobacionService;
use App\Services\ImporteConLetra;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Spatie\Activitylog\Models\Activity;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * Feature coverage for the facturas (fase B del fondo rotatorio): the CFDI
 * reader, every comprobación rule, the links with cotización and partidas,
 * the private XML/PDF files and the COMPRA DE INSUMOS / REPORTE_COMPROBACION
 * workbooks. The XML fixtures are built like the real CFDI 4.0 / 3.3 files.
 * Dummy factory data only (.claude/rules/testing.md).
 */
class FacturaTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Estacion $estacion;

    private SolicitudInsumo $solicitud;

    private SolicitudInsumoPartida $coladera;

    private SolicitudInsumoPartida $aceite;

    private SolicitudInsumoPartida $pintura;

    private SolicitudInsumoPartida $cinta;

    private Cotizacion $cotizacion;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        Proveedor::factory()->create(['nombre' => 'Continental', 'iva' => 8]);

        $this->estacion = Estacion::factory()->create(['nombre' => 'Puerto Morelos', 'codigo' => '23', 'orden' => 1]);
        $this->solicitud = SolicitudInsumo::factory()->create(['estacion_id' => $this->estacion->id, 'anio' => 2026, 'mes' => 12, 'estado' => SolicitudInsumo::APROBADA]);

        $this->coladera = $this->partida(1, 'Coladera universal Coflex blanca con rejilla plastica cromada', 4, 50, '24801');
        $this->aceite = $this->partida(2, 'Aceite de dos tiempos', 2, 100, '26105');
        $this->pintura = $this->partida(3, 'Pintura vinilica blanca', 1, 200, '24901');
        $this->cinta = $this->partida(4, 'Cinta de aislar negra', 5, 20, '24901');

        // Cotización 135 de Continental: coladera y aceite (ya con precio real) y pintura.
        $this->cotizacion = Cotizacion::query()->create(['anio' => 2026, 'mes' => 12, 'proveedor' => 'Continental', 'folio' => '135', 'fecha' => '2026-09-28', 'validacion' => 'OK']);
        $this->renglon(1, 'PC101', $this->coladera, 4, 53.27);
        $this->renglon(2, 'AC1', $this->aceite, 2, 106.35);
        $this->renglon(3, 'PT1', $this->pintura, 1, 200);
        $this->coladera->update(['precio_estimado' => 50, 'precio_unitario' => 53.27]);
        $this->aceite->update(['precio_estimado' => 100, 'precio_unitario' => 106.35]);
    }

    private function partida(int $numero, string $descripcion, float $cantidad, float $precio, string $pptal): SolicitudInsumoPartida
    {
        return SolicitudInsumoPartida::factory()->create([
            'solicitud_insumo_id' => $this->solicitud->id, 'numero' => $numero, 'descripcion' => $descripcion, 'cantidad' => $cantidad,
            'precio_unitario' => $precio, 'partida_presupuestal' => $pptal, 'unidad' => 'Pieza', 'justificacion' => 'Reposición',
        ]);
    }

    private function renglon(int $numero, string $clave, SolicitudInsumoPartida $partida, float $cantidad, float $precio): CotizacionRenglon
    {
        return $this->cotizacion->renglones()->create([
            'solicitud_insumo_partida_id' => $partida->id, 'numero' => $numero, 'clave' => $clave, 'descripcion' => $partida->descripcion,
            'cantidad' => $cantidad, 'precio_unitario' => $precio, 'importe' => round($cantidad * $precio, 2), 'coincidencia' => 'mismo', 'estatus' => 'actualizado',
        ]);
    }

    protected function userWithRole(string $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * XML de factura como los que emite un PAC.
     *
     * @param  list<array{0: string|null, 1: string, 2: float, 3: float, 4?: float|null}>  $conceptos  [clave, descripción, cantidad, valor unitario, tasa]
     * @param  array<string, mixed>  $o
     */
    protected function cfdi(array $o = []): string
    {
        $o += [
            'uuid' => 'AAAAAAAA-0000-4000-8000-'.str_pad((string) random_int(1, 999999999999), 12, '0', STR_PAD_LEFT), 'folio' => '000135RI', 'serie' => null,
            'receptor' => 'TMA220603D80', 'emisor' => 'GFC051130D29', 'nombre' => 'GRUPO FERRETERO CONTINENTAL', 'tipo' => 'I', 'moneda' => 'MXN',
            'notas' => 'COT - 135 - RI - 135', 'isr' => 0.0, 'version' => '4.0', 'total' => null, 'subtotal' => null,
            'conceptos' => [['PC101', 'COLADERA UNIVERSAL COFLEX - COLOR BLANCA C/REJILLA PLASTICA CROMADA', 4, 53.27, 0.08], ['AC1', 'ACEITE DE DOS TIEMPOS', 2, 106.35, 0.08]],
        ];

        $ns = $o['version'] === '3.3' ? 'http://www.sat.gob.mx/cfd/3' : 'http://www.sat.gob.mx/cfd/4';
        $subtotal = 0.0;
        $iva = 0.0;
        $nodos = '';

        foreach ($o['conceptos'] as [$clave, $descripcion, $cantidad, $precio, $tasa]) {
            $importe = round($cantidad * $precio, 2);
            $impuesto = $tasa === null ? 0.0 : round($importe * $tasa, 2);
            $subtotal += $importe;
            $iva += $impuesto;
            $traslado = $tasa === null ? '' : '<cfdi:Impuestos><cfdi:Traslados><cfdi:Traslado Base="'.$importe.'" Impuesto="002" TipoFactor="Tasa" TasaOCuota="'.number_format($tasa, 6, '.', '').'" Importe="'.$impuesto.'"/></cfdi:Traslados></cfdi:Impuestos>';
            $nodos .= '<cfdi:Concepto ClaveProdServ="40141700" '.($clave ? 'NoIdentificacion="'.$clave.'" ' : '').'Cantidad="'.$cantidad.'" ClaveUnidad="H87" Unidad="PIEZA" Descripcion="'.htmlspecialchars($descripcion).'" ValorUnitario="'.$precio.'" Importe="'.$importe.'" ObjetoImp="02">'.$traslado.'</cfdi:Concepto>';
        }

        $subtotal = $o['subtotal'] ?? round($subtotal, 2);
        $total = $o['total'] ?? round($subtotal + $iva - $o['isr'], 2);
        $retencion = $o['isr'] > 0 ? '<cfdi:Retenciones><cfdi:Retencion Impuesto="001" Importe="'.$o['isr'].'"/></cfdi:Retenciones>' : '';
        $traslados = $iva > 0 ? '<cfdi:Traslados><cfdi:Traslado Base="'.$subtotal.'" Impuesto="002" TipoFactor="Tasa" TasaOCuota="'.number_format($iva / max($subtotal, 0.01), 6, '.', '').'" Importe="'.round($iva, 2).'"/></cfdi:Traslados>' : '';

        return '<?xml version="1.0" encoding="utf-8"?>'
            .'<cfdi:Comprobante xmlns:cfdi="'.$ns.'" Version="'.$o['version'].'" '.($o['serie'] ? 'Serie="'.$o['serie'].'" ' : '').'Folio="'.$o['folio'].'" Fecha="2026-10-05T00:00:00" SubTotal="'.$subtotal.'" Moneda="'.$o['moneda'].'" Total="'.$total.'" TipoDeComprobante="'.$o['tipo'].'" MetodoPago="PPD">'
            .'<cfdi:Emisor Rfc="'.$o['emisor'].'" Nombre="'.htmlspecialchars($o['nombre']).'" RegimenFiscal="601"/>'
            .'<cfdi:Receptor Rfc="'.$o['receptor'].'" Nombre="TREN MAYA" UsoCFDI="G03"/>'
            .'<cfdi:Conceptos>'.$nodos.'</cfdi:Conceptos>'
            .'<cfdi:Impuestos TotalImpuestosTrasladados="'.round($iva, 2).'">'.$retencion.$traslados.'</cfdi:Impuestos>'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.$o['uuid'].'" FechaTimbrado="2026-10-05T12:51:15"/></cfdi:Complemento>'
            .($o['notas'] ? '<cfdi:Addenda><Notas>'.$o['notas'].'</Notas></cfdi:Addenda>' : '')
            .'</cfdi:Comprobante>';
    }

    private function subir(string|array $xml, array $extra = []): TestResponse
    {
        $archivos = array_map(fn (string $contenido, int $i) => UploadedFile::fake()->createWithContent('factura'.$i.'.xml', $contenido), (array) $xml, array_keys((array) $xml));

        $pdfs = array_map(fn (int $i) => UploadedFile::fake()->createWithContent('factura'.$i.'.pdf', "%PDF-1.4\n"), array_keys((array) $xml));

        return $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12, 'xml' => $archivos, 'pdf' => $pdfs] + $extra);
    }

    protected function cargar(array $o = []): Factura
    {
        $this->subir($this->cfdi($o))->assertSessionHasNoErrors();

        return Factura::query()->latest('id')->firstOrFail();
    }

    /**
     * @return list<string> "TIPO:CODIGO" de cada hallazgo de la factura.
     */
    private function hallazgos(Factura $factura): array
    {
        return $factura->hallazgos()->get()->map(fn ($h) => $h->tipo.':'.$h->codigo)->all();
    }

    private function libro(TestResponse $respuesta): Spreadsheet
    {
        $ruta = tempnam(sys_get_temp_dir(), 'fac').'.xlsx';
        file_put_contents($ruta, $respuesta->streamedContent());

        return IOFactory::load($ruta);
    }

    // --- Lector CFDI ------------------------------------------------------------------

    public function test_the_parser_reads_a_cfdi_4_and_a_cfdi_3_3(): void
    {
        $parser = new CfdiParser;

        $d = $parser->leer($this->cfdi(['uuid' => '5605D1D9-4C53-46EB-8A79-EC51BF5E79B7', 'isr' => 55.09]));
        $this->assertSame('5605D1D9-4C53-46EB-8A79-EC51BF5E79B7', $d['uuid']);
        $this->assertSame('000135RI', $d['folio']);
        $this->assertSame('GFC051130D29', $d['emisor_rfc']);
        $this->assertSame('TMA220603D80', $d['receptor_rfc']);
        $this->assertSame('2026-10-05 00:00:00', $d['fecha']);
        $this->assertSame('135', $d['referencia_cot']);
        $this->assertSame(55.09, $d['isr_retenido']);
        $this->assertSame(34.07, $d['iva']);
        $this->assertCount(2, $d['conceptos']);
        $this->assertSame('PC101', $d['conceptos'][0]['no_identificacion']);
        $this->assertSame(8.0, $d['conceptos'][0]['iva_tasa']);
        $this->assertSame(213.08, $d['conceptos'][0]['importe']);

        $v33 = $parser->leer($this->cfdi(['version' => '3.3', 'notas' => null]));
        $this->assertSame('GFC051130D29', $v33['emisor_rfc']);
        $this->assertNull($v33['referencia_cot']);
    }

    public function test_the_parser_rejects_dangerous_or_invalid_xml(): void
    {
        $parser = new CfdiParser;
        $valido = $this->cfdi();
        $casos = [
            'DOCTYPE (XXE)' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'.substr($valido, strpos($valido, '<cfdi:Comprobante')),
            'bomba de entidades' => '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;">]><lolz>&lol2;</lolz>',
            'no es XML' => 'esto no es un xml',
            'no es CFDI' => '<?xml version="1.0"?><otra><cosa/></otra>',
            'sin timbrar' => preg_replace('/<cfdi:Complemento>.*<\/cfdi:Complemento>/s', '', $valido),
            'sin conceptos' => preg_replace('/<cfdi:Conceptos>.*<\/cfdi:Conceptos>/s', '<cfdi:Conceptos/>', $valido),
            'demasiado grande' => $valido.str_repeat(' ', CfdiParser::MAX_BYTES),
        ];

        foreach ($casos as $nombre => $xml) {
            try {
                $parser->leer($xml);
                $this->fail('Debió rechazar: '.$nombre);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage(), $nombre);
            }
        }
    }

    public function test_amount_in_words_matches_the_real_folios(): void
    {
        $this->assertSame('Dos mil setecientos quince pesos 11/100 M.N.', ImporteConLetra::convertir(2715.11));
        $this->assertSame('Mil ochenta y cinco pesos 15/100 M.N.', ImporteConLetra::convertir(1085.15));
        $this->assertSame('Ocho mil cincuenta y un pesos 36/100 M.N.', ImporteConLetra::convertir(8051.36));
        $this->assertSame('Un peso 00/100 M.N.', ImporteConLetra::convertir(1));
        $this->assertSame('Veintiún mil pesos 00/100 M.N.', ImporteConLetra::convertir(21000));
        $this->assertSame('Un millón de pesos 00/100 M.N.', ImporteConLetra::convertir(1000000));
    }

    // --- Acceso -------------------------------------------------------------------------

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('insumos.facturas.index'))->assertRedirect(route('login'));
        $this->post(route('insumos.facturas.store'), [])->assertRedirect(route('login'));
    }

    public function test_estacion_cannot_use_facturas_but_zone_roles_and_edificio_can(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));
        $this->get(route('insumos.facturas.index'))->assertForbidden();
        $this->subir($this->cfdi())->assertForbidden();
        $this->assertSame(0, Factura::query()->count());

        foreach (['Jefe de Zona', 'Administrador', 'Edificio'] as $rol) {
            $this->actingAsTwoFactorVerified($this->userWithRole($rol));
            $this->get(route('insumos.facturas.index', ['anio' => 2026, 'mes' => 12]))->assertOk();
        }
    }

    // --- Carga y vínculos ----------------------------------------------------------------

    public function test_a_matching_invoice_is_loaded_linked_by_notas_and_comes_out_ok(): void
    {
        $this->actingAsTwoFactorVerified($usuario = $this->userWithRole('Jefe de Zona'));

        $factura = $this->cargar(['uuid' => '5605D1D9-4C53-46EB-8A79-EC51BF5E79B7']);

        $this->assertSame('5605D1D9-4C53-46EB-8A79-EC51BF5E79B7', $factura->uuid);
        $this->assertSame($this->cotizacion->id, $factura->cotizacion_id);
        $this->assertSame('notas', $factura->vinculo_cotizacion);
        $this->assertSame($this->estacion->id, $factura->estacion_id);
        $this->assertSame('OK', $factura->estado);
        $this->assertSame('459.85', $factura->total);
        $this->assertSame(2, $factura->conceptos()->count());

        $coladera = $factura->conceptos()->where('no_identificacion', 'PC101')->firstOrFail();
        $this->assertSame($this->coladera->id, $coladera->solicitud_insumo_partida_id);
        $this->assertSame('clave', $coladera->vinculo);

        Storage::disk('local')->assertExists($factura->xml_ruta);
        $this->assertSame(['OK:CONCEPTO_OK', 'OK:CONCEPTO_OK'], $this->hallazgos($factura));
        $this->assertTrue(Activity::query()->where('log_name', 'factura')->where('subject_id', $factura->id)->exists());
        $this->assertSame('GFC051130D29', Proveedor::query()->where('nombre', 'Continental')->value('rfc')); // el RFC del emisor queda en el proveedor
    }

    public function test_the_same_uuid_cannot_be_loaded_twice_and_bad_files_are_reported_one_by_one(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $uuid = '11111111-1111-4111-8111-111111111111';
        $this->cargar(['uuid' => $uuid]);

        $respuesta = $this->subir([$this->cfdi(['uuid' => $uuid]), 'esto no es un xml', $this->cfdi(['uuid' => '22222222-2222-4222-8222-222222222222', 'folio' => '000136RI'])]);

        $respuesta->assertRedirect()->assertSessionHas('resultados_facturas');
        $resultados = session('resultados_facturas');
        $this->assertFalse($resultados[0]['ok']);
        $this->assertStringContainsString('ya está cargada', $resultados[0]['mensaje']);
        $this->assertFalse($resultados[1]['ok']);
        $this->assertTrue($resultados[2]['ok']);
        $this->assertSame(2, Factura::query()->count());
    }

    public function test_upload_validation(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12])->assertInvalid('xml');
        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12, 'xml' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertInvalid('xml.0');
        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 13, 'xml' => [UploadedFile::fake()->createWithContent('a.xml', $this->cfdi())], 'pdf' => [UploadedFile::fake()->createWithContent('a.pdf', "%PDF-1.4\n")]])->assertInvalid('mes');
        // El PDF de la factura es obligatorio.
        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12, 'xml' => [UploadedFile::fake()->createWithContent('a.xml', $this->cfdi())]])->assertInvalid('pdf');
        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12, 'xml' => [UploadedFile::fake()->createWithContent('a.xml', $this->cfdi())], 'pdf' => [UploadedFile::fake()->create('virus.exe', 10)]])->assertInvalid('pdf.0');
        $this->assertSame(0, Factura::query()->count());
    }

    public function test_xml_is_paired_with_its_pdf_by_name_and_without_a_pdf_it_is_not_loaded(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $a = UploadedFile::fake()->createWithContent('Uno.xml', $this->cfdi());
        $b = UploadedFile::fake()->createWithContent('dos.xml', $this->cfdi());

        $this->post(route('insumos.facturas.store'), ['anio' => 2026, 'mes' => 12, 'xml' => [$a, $b], 'pdf' => [UploadedFile::fake()->createWithContent('UNO.pdf', "%PDF-1.4\n"), UploadedFile::fake()->createWithContent('otro.pdf', "%PDF-1.4\n")]])->assertSessionHasNoErrors();

        $resultados = session('resultados_facturas');
        $this->assertTrue($resultados[0]['ok']);
        $this->assertFalse($resultados[1]['ok']);
        $this->assertStringContainsString('Falta el PDF', $resultados[1]['mensaje']);
        $this->assertSame(1, Factura::query()->count());
        $this->assertNotNull(Factura::query()->firstOrFail()->pdf_ruta);
    }

    public function test_without_notas_the_cotizacion_is_found_by_clave_and_then_by_content(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $porClave = $this->cargar(['notas' => null]);
        $this->assertSame('claves', $porClave->vinculo_cotizacion);
        $this->assertSame($this->cotizacion->id, $porClave->cotizacion_id);

        $porContenido = $this->cargar(['notas' => null, 'conceptos' => [[null, 'Coladera universal Coflex blanca con rejilla plastica cromada', 4, 53.27, 0.08], [null, 'Aceite de dos tiempos', 2, 106.35, 0.08]]]);
        $this->assertSame('contenido', $porContenido->vinculo_cotizacion);
        $this->assertContains('REVISAR:VINCULO_CONFIANZA_BAJA', $this->hallazgos($porContenido));
    }

    public function test_an_invoice_without_cotizacion_is_compared_with_the_documento_and_flagged(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $factura = $this->cargar(['notas' => null, 'conceptos' => [['ZZ9', 'Cinta de aislar negra', 5, 20, 0.08]]]);

        $this->assertNull($factura->cotizacion_id);
        $this->assertSame($this->cinta->id, $factura->conceptos()->firstOrFail()->solicitud_insumo_partida_id);
        $this->assertSame('documento', $factura->conceptos()->firstOrFail()->vinculo);
        $this->assertContains('REVISAR:FACTURA_SIN_COTIZACION', $this->hallazgos($factura));
        $this->assertNotContains('ERROR:PRECIO_DISTINTO', $this->hallazgos($factura)); // el estimado no sirve de referencia
        $this->assertSame('REVISAR', $factura->estado);
    }

    // --- Reglas de la comprobación ----------------------------------------------------------

    public function test_price_differences_follow_the_tolerances(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $con = fn (float $precio) => [['PC101', 'COLADERA', 4, $precio, 0.08]];

        $redondeo = $this->cargar(['conceptos' => $con(53.30)]);        // 3 centavos
        $this->assertNotContains('ALERTA:PRECIO_DISTINTO', $this->hallazgos($redondeo));
        $this->assertNotContains('ERROR:PRECIO_DISTINTO', $this->hallazgos($redondeo));

        $alerta = $this->cargar(['conceptos' => $con(54.50)]);          // +2.3 %
        $this->assertContains('ALERTA:PRECIO_DISTINTO', $this->hallazgos($alerta));

        $error = $this->cargar(['conceptos' => $con(59.07)]);           // +10.9 %
        $this->assertContains('ERROR:PRECIO_DISTINTO', $this->hallazgos($error));
        $this->assertSame('ERROR', $error->estado);

        $hallazgo = $error->hallazgos()->where('codigo', 'PRECIO_DISTINTO')->firstOrFail();
        $this->assertSame('53.2700', $hallazgo->valor_cotizado);
        $this->assertSame('59.0700', $hallazgo->valor_facturado);
        $this->assertSame('5.8000', $hallazgo->diferencia);
    }

    public function test_quantity_receptor_type_currency_and_unmatched_lines(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $cantidad = $this->cargar(['conceptos' => [['PC101', 'COLADERA', 3, 53.27, 0.08]]]);
        $this->assertContains('ERROR:CANTIDAD_DISTINTA', $this->hallazgos($cantidad));

        $receptor = $this->cargar(['receptor' => 'XAXX010101000']);
        $this->assertContains('ERROR:RECEPTOR_DISTINTO', $this->hallazgos($receptor));

        $tipo = $this->cargar(['tipo' => 'P']);
        $this->assertContains('ERROR:TIPO_NO_SOPORTADO', $this->hallazgos($tipo));

        $moneda = $this->cargar(['moneda' => 'USD']);
        $this->assertContains('ALERTA:MONEDA_DISTINTA', $this->hallazgos($moneda));

        $sinEquivalente = $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 53.27, 0.08], ['QQ7', 'Taladro percutor industrial', 1, 999, 0.08]]]);
        $this->assertContains('REVISAR:RENGLON_SIN_EQUIVALENTE', $this->hallazgos($sinEquivalente));
    }

    public function test_the_same_clave_with_another_description_is_an_alert(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $factura = $this->cargar(['conceptos' => [['PC101', 'Manguera flexible de jardin 15 m', 4, 53.27, 0.08]]]);

        $this->assertContains('ALERTA:DESCRIPCION_DISTINTA', $this->hallazgos($factura));
    }

    public function test_iva_is_checked_against_the_providers_rate(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $mal = $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 53.27, 0.16]]]);
        $this->assertContains('ERROR:IVA_NO_CORRESPONDE', $this->hallazgos($mal));

        Proveedor::query()->where('nombre', 'Continental')->update(['iva' => 16]);
        $bien = $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 53.27, 0.16]]]);
        $this->assertNotContains('ERROR:IVA_NO_CORRESPONDE', $this->hallazgos($bien));
        $this->assertNotContains('ERROR:IVA_TOTAL_NO_CUADRA', $this->hallazgos($bien));
    }

    public function test_totals_and_isr_are_validated(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));

        $total = $this->cargar(['total' => 999.99]);
        $this->assertContains('ERROR:TOTAL_NO_CUADRA', $this->hallazgos($total));

        $suma = $this->cargar(['subtotal' => 100.00, 'total' => 108.00]);
        $this->assertContains('ERROR:SUMA_NO_CUADRA', $this->hallazgos($suma));

        $inesperado = $this->cargar(['isr' => 5.00]);
        $this->assertContains('ALERTA:ISR_INESPERADO', $this->hallazgos($inesperado));

        Proveedor::query()->where('nombre', 'Continental')->update(['retiene_isr' => true]);
        $faltante = $this->cargar([]);
        $this->assertContains('ALERTA:ISR_FALTANTE', $this->hallazgos($faltante));

        $correcto = $this->cargar(['isr' => 5.32]);
        $this->assertNotContains('ALERTA:ISR_FALTANTE', $this->hallazgos($correcto));
        $this->assertNotContains('ALERTA:ISR_INESPERADO', $this->hallazgos($correcto));
        $this->assertNotContains('ERROR:TOTAL_NO_CUADRA', $this->hallazgos($correcto));
    }

    public function test_an_unknown_issuer_is_found_by_rfc_once_registered(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        Proveedor::query()->delete();

        $sinRegistro = $this->cargar(['notas' => null, 'conceptos' => [['ZZ', 'Producto raro', 1, 10, 0.08]]]);
        $this->assertContains('ALERTA:PROVEEDOR_NO_REGISTRADO', $this->hallazgos($sinRegistro));

        Proveedor::factory()->create(['nombre' => 'Otro nombre', 'rfc' => 'GFC051130D29', 'iva' => 16]);
        $conRfc = $this->cargar(['notas' => null, 'conceptos' => [['ZZ', 'Producto raro', 1, 10, 0.08]]]);
        $this->assertNotContains('ALERTA:PROVEEDOR_NO_REGISTRADO', $this->hallazgos($conRfc));
        $this->assertContains('ERROR:IVA_NO_CORRESPONDE', $this->hallazgos($conRfc)); // el proveedor por RFC factura al 16 %
    }

    // --- Ajustes manuales ---------------------------------------------------------------------

    public function test_manual_links_survive_a_new_analysis(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $factura = $this->cargar(['notas' => null, 'conceptos' => [['ZZ9', 'Cosa sin parecido alguno', 1, 10, 0.08]]]);
        $concepto = $factura->conceptos()->firstOrFail();
        $this->assertNull($concepto->solicitud_insumo_partida_id);

        $this->put(route('insumos.facturas.concepto', $concepto), ['partida_id' => $this->cinta->id])->assertRedirect(route('insumos.facturas.show', $factura));
        $this->assertSame($this->cinta->id, $concepto->fresh()->solicitud_insumo_partida_id);
        $this->assertSame('manual', $concepto->fresh()->vinculo);

        $this->put(route('insumos.facturas.update', $factura), ['cotizacion_id' => $this->cotizacion->id, 'estacion_id' => $this->estacion->id])->assertRedirect();
        $factura->refresh();
        $this->assertSame($this->cotizacion->id, $factura->cotizacion_id);
        $this->assertSame('manual', $factura->vinculo_cotizacion);
        $this->assertSame($this->cinta->id, $concepto->fresh()->solicitud_insumo_partida_id);

        app(ComprobacionService::class)->analizar($factura);
        $this->assertSame('manual', $factura->fresh()->vinculo_cotizacion);
        $this->assertSame('manual', $concepto->fresh()->vinculo);
    }

    public function test_manual_links_only_accept_data_of_the_same_month(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $factura = $this->cargar();
        $otraCot = Cotizacion::query()->create(['anio' => 2026, 'mes' => 11, 'proveedor' => 'X', 'folio' => '9', 'validacion' => 'OK']);
        $otroMes = SolicitudInsumoPartida::factory()->create(['solicitud_insumo_id' => SolicitudInsumo::factory()->create(['anio' => 2026, 'mes' => 11, 'estado' => 'aprobada'])->id, 'numero' => 1]);

        $this->put(route('insumos.facturas.update', $factura), ['cotizacion_id' => $otraCot->id])->assertInvalid('cotizacion_id');
        $this->put(route('insumos.facturas.concepto', $factura->conceptos()->firstOrFail()), ['partida_id' => $otroMes->id])->assertInvalid('partida_id');
        $this->put(route('insumos.facturas.update', $factura), ['cotizacion_id' => 999999])->assertInvalid('cotizacion_id');
    }

    // --- Archivos --------------------------------------------------------------------------------

    public function test_xml_and_pdf_are_private_and_deleted_with_the_invoice(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $factura = $this->cargar();

        $this->post(route('insumos.facturas.pdf', $factura), ['archivo' => UploadedFile::fake()->createWithContent('f.pdf', "%PDF-1.4\n")])->assertRedirect();
        $factura->refresh();
        Storage::disk('local')->assertExists($factura->pdf_ruta);

        $this->get(route('insumos.facturas.archivo', [$factura, 'xml']))->assertOk()->assertHeader('Content-Type', 'application/xml');
        $this->get(route('insumos.facturas.archivo', [$factura, 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('insumos.facturas.pdf', $factura), ['archivo' => UploadedFile::fake()->create('virus.exe', 10)])->assertInvalid('archivo');

        $this->actingAsTwoFactorVerified($this->userWithRole('Estación', ['estacion_id' => $this->estacion->id]));
        $this->get(route('insumos.facturas.archivo', [$factura, 'xml']))->assertForbidden();

        $this->actingAsTwoFactorVerified($this->userWithRole('Administrador'));
        $this->delete(route('insumos.facturas.destroy', $factura))->assertRedirect();
        Storage::disk('local')->assertMissing($factura->xml_ruta);
        Storage::disk('local')->assertMissing($factura->pdf_ruta);
        $this->assertSame(0, Factura::query()->count());
    }

    // --- Pantallas ----------------------------------------------------------------------------------

    public function test_index_and_show_render_and_the_board_links_to_them(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Edificio'));
        $factura = $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 59.07, 0.08]]]);

        $this->get(route('insumos.facturas.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('000135RI')->assertSee('GRUPO FERRETERO CONTINENTAL')->assertSee('ERROR');
        $this->get(route('insumos.facturas.show', $factura))->assertOk()->assertSee('PRECIO_DISTINTO')->assertSee('NO_VERIFICADO')->assertSee($factura->uuid);
        $this->get(route('insumos.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee(e(route('insumos.facturas.index', ['anio' => 2026, 'mes' => 12])), false);
    }

    // --- Excel ----------------------------------------------------------------------------------------

    public function test_the_compra_de_insumos_workbook(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->cargar(['uuid' => '5605D1D9-4C53-46EB-8A79-EC51BF5E79B7']);

        $respuesta = $this->get(route('insumos.facturas.descargar', ['anio' => 2026, 'mes' => 12, 'tipo' => 'compra']))->assertOk();
        $this->assertStringContainsString('COMPRA DE INSUMOS_DIC.xlsx', $respuesta->headers->get('Content-Disposition'));

        $libro = $this->libro($respuesta);
        $this->assertSame(['FACTURADO', 'NO FACTURADO', 'FOLIOS'], $libro->getSheetNames());

        $f = $libro->getSheetByName('FACTURADO');
        $this->assertSame('23_PUERTO MORELOS', $f->getCell('B7')->getValue());
        $this->assertSame('PPTAL', $f->getCell('M9')->getValue());
        $this->assertSame(4.0, (float) $f->getCell('C10')->getValue());
        $this->assertSame($this->coladera->descripcion, $f->getCell('E10')->getValue());      // la descripción del documento
        $this->assertEquals(53.27, $f->getCell('F10')->getValue());                              // precio real de la factura
        $this->assertSame('=C10*F10', $f->getCell('G10')->getValue());
        $this->assertSame('24801', $f->getCell('L10')->getValue());
        $this->assertSame('=SUM(G10:G11)', $f->getCell('G12')->getValue());
        $this->assertSame('000135RI', $f->getCell('L12')->getValue());
        $this->assertSame('IVA 8 %', $f->getCell('E13')->getValue());
        $this->assertEquals(34.07, $f->getCell('G13')->getValue());                              // el IVA exacto de la factura
        $this->assertSame('=SUM(G12:G13)', $f->getCell('G14')->getValue());
        $this->assertSame('=SUM(G14)', $f->getCell('K3')->getValue());
        $this->assertEquals(459.85, round((float) $f->getCell('G14')->getCalculatedValue(), 2));        // igual al total del CFDI
        $this->assertSame('24801', $f->getCell('M10')->getValue());                              // resumen por partida
        $this->assertSame('=G10', $f->getCell('N10')->getValue());
        $this->assertSame('=8%*G10', $f->getCell('O10')->getValue());
        $this->assertSame('=N10+O10', $f->getCell('P10')->getValue());

        $nf = $libro->getSheetByName('NO FACTURADO');
        $this->assertSame('Puerto Morelos', $nf->getCell('B7')->getValue());
        $motivos = collect([$nf->getCell('L9')->getValue(), $nf->getCell('L10')->getValue(), $nf->getCell('L11')->getValue()]);
        $this->assertTrue($motivos->contains('Cotizado (cot. 135) y no facturado'));
        $this->assertTrue($motivos->contains('Sin cotización ni factura'));

        $fo = $libro->getSheetByName('FOLIOS');
        $this->assertSame('23_Puerto Morelos', $fo->getCell('B2')->getValue());
        $this->assertSame('5605D1D9-4C53-46EB-8A79-EC51BF5E79B7', $fo->getCell('D2')->getValue());
        $this->assertSame('=FACTURADO!G14', $fo->getCell('G2')->getValue());
        $this->assertSame('Cuatrocientos cincuenta y nueve pesos 85/100 M.N.', $fo->getCell('H2')->getValue());
        $this->assertStringContainsString('24801 “Materiales complementarios”', $fo->getCell('I2')->getValue());
        $this->assertStringContainsString('26105', $fo->getCell('I2')->getValue());
        $this->assertSame('=SUM(G2:G2)', $fo->getCell('G3')->getValue());
    }

    public function test_the_reporte_de_comprobacion_workbook(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 59.07, 0.08], ['AC1', 'ACEITE DE DOS TIEMPOS', 2, 106.35, 0.08]]]);
        Cotizacion::query()->create(['anio' => 2026, 'mes' => 12, 'proveedor' => 'Truper', 'folio' => '77', 'validacion' => 'OK']);

        $respuesta = $this->get(route('insumos.facturas.descargar', ['anio' => 2026, 'mes' => 12, 'tipo' => 'reporte']))->assertOk();
        $this->assertStringContainsString('REPORTE_COMPROBACION_DIC.xlsx', $respuesta->headers->get('Content-Disposition'));

        $libro = $this->libro($respuesta);
        $this->assertSame(['Resumen', 'Hallazgos', 'Por factura', 'Por estación', 'Sin vínculo', 'Fechas (solo informativo)'], $libro->getSheetNames());

        $resumen = $libro->getSheetByName('Resumen');
        $this->assertSame('Facturas leídas', $resumen->getCell('A3')->getValue());
        $this->assertEquals(1, $resumen->getCell('B3')->getValue());
        $this->assertSame('NO_VERIFICADO', $resumen->getCell('B13')->getValue());

        $textos = collect($libro->getSheetByName('Hallazgos')->toArray())->flatten()->filter()->implode('|');
        $this->assertStringContainsString('PRECIO_DISTINTO', $textos);
        $this->assertStringContainsString('ERROR', $textos);

        $porFactura = $libro->getSheetByName('Por factura');
        $this->assertSame('GRUPO FERRETERO CONTINENTAL', $porFactura->getCell('B2')->getValue());
        $this->assertSame('ERROR', $porFactura->getCell('O2')->getValue());
        $this->assertSame('Puerto Morelos', $libro->getSheetByName('Por estación')->getCell('A2')->getValue());

        $sinVinculo = collect($libro->getSheetByName('Sin vínculo')->toArray())->flatten()->filter()->implode('|');
        $this->assertStringContainsString('Cotización sin factura', $sinVinculo);
        $this->assertStringContainsString('Truper 77', $sinVinculo);
    }

    public function test_the_summary_flags_when_the_invoices_exceed_the_fondo(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->cargar(['conceptos' => [['PC101', 'COLADERA', 4, 53.27, 0.08]]]);
        $this->cargar(['conceptos' => [['ZZ', 'Lote enorme', 1000, 60, 0.08]], 'notas' => null]);

        $resumen = app(ComprobacionService::class)->resumen(2026, 12);

        $this->assertSame(2, $resumen['facturas']);
        $this->assertTrue($resumen['excede']);
        $this->assertLessThan(0, $resumen['pendiente']);
        $this->assertSame('NO_VERIFICADO', $resumen['sat']);

        $this->get(route('insumos.facturas.index', ['anio' => 2026, 'mes' => 12]))->assertOk()->assertSee('Excede el fondo');
    }

    public function test_text_from_the_xml_is_written_as_text_in_the_excel(): void
    {
        $this->actingAsTwoFactorVerified($this->userWithRole('Jefe de Zona'));
        $this->cargar(['nombre' => '=HYPERLINK("http://x.test","clic")', 'notas' => null, 'conceptos' => [['ZZ', 'Producto', 1, 10, 0.08]]]);

        $libro = $this->libro($this->get(route('insumos.facturas.descargar', ['anio' => 2026, 'mes' => 12, 'tipo' => 'compra'])));
        $celda = $libro->getSheetByName('FOLIOS')->getCell('C2');

        $this->assertSame('s', $celda->getDataType());
        $this->assertSame('=HYPERLINK("http://x.test","clic")', $celda->getValue());
    }
}
