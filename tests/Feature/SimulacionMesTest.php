<?php

namespace Tests\Feature;

use App\Models\CatalogoInsumo;
use App\Models\CotizacionRenglon;
use App\Models\Estacion;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\User;
use App\Services\CatalogoInsumoService;
use App\Services\ComprobacionService;
use App\Services\ConsolidadoInsumosService;
use App\Services\CotizacionService;
use App\Services\SolicitudInsumoService;
use Database\Seeders\EstacionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as LectorXlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * SIMULACIÓN del mes de septiembre 2026 con los archivos reales del área
 * (ANEXO enviado, documento rectificado y COMPRA DE INSUMOS facturada).
 * NO se versiona: usa una base desechable y escribe sus resultados en
 * Documents\Simulacion_Septiembre.
 */
class SimulacionMesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const BASE = 'C:/Users/SEDENA/Documents/Autoamtizaciones-contable/';

    private const SALIDA = 'C:/Users/SEDENA/Documents/Simulacion_Septiembre/';

    private const ANIO = 2026;

    private const MES = 9;

    /** @var list<string> */
    private array $informe = [];

    private function nota(string $texto = ''): void
    {
        $this->informe[] = $texto;
        fwrite(STDERR, $texto."\n");
    }

    private function capturar(string $nombre, string $html): void
    {
        $dir = 'C:/Users/SEDENA/Documents/Simulacion_Septiembre/capturas_html/';
        @mkdir($dir, 0777, true);
        $base = 'file:///C:/Users/SEDENA/dashboard_tren_maya/public/';
        $html = str_replace(['http://localhost/build/', 'http://localhost/logo.png', 'http://localhost/favicon.png'], [$base.'build/', $base.'logo.png', $base.'favicon.png'], $html);
        file_put_contents($dir.$nombre.'.html', $html);
    }

    private function codigoDe(string $titulo): ?string
    {
        $n = Str::ascii(mb_strtolower($titulo));

        return match (true) {
            str_contains($n, 'aeropuerto') => '26',
            str_contains($n, 'tulum') => '25',
            str_contains($n, 'puerto morelos') => '23',
            str_contains($n, 'playa') => '24',
            str_contains($n, 'felipe') => '27',
            str_contains($n, 'limones') => '28',
            str_contains($n, 'bacalar') => '29',
            str_contains($n, 'chetumal') => '30',
            str_contains($n, 'nicolas'), str_contains($n, 'kohunlich') => '31',
            str_contains($n, 'edificio') => '00',
            default => null,
        };
    }

    /**
     * Bloques de estación de un ANEXO / COMPRA: una lista (una estación puede repetirse).
     *
     * @return list<array{titulo: string, codigo: string|null, lineas: list<array<string, mixed>>, ref: string|null}>
     */
    private function bloques(string $ruta, ?string $hoja = null): array
    {
        $lector = new LectorXlsx;
        $lector->setReadDataOnly(false);
        $libro = $lector->load($ruta);
        $ws = $hoja ? $libro->getSheetByName($hoja) : $libro->getSheet(0);
        $bloques = [];
        $actual = null;

        for ($i = 1; $i <= $ws->getHighestRow(); $i++) {
            $b = trim((string) $ws->getCell('B'.$i)->getValue());

            if ($b !== '' && ! is_numeric($b) && $b !== 'N°' && trim((string) $ws->getCell('B'.($i + 2))->getValue()) === 'N°') {
                $bloques[] = ['titulo' => $b, 'codigo' => $this->codigoDe($b), 'lineas' => [], 'ref' => null];
                $actual = array_key_last($bloques);

                continue;
            }

            if ($actual === null) {
                continue;
            }

            $e = trim((string) $ws->getCell('E'.$i)->getValue());

            if ($e === 'Subtotal') {
                $bloques[$actual]['ref'] = trim((string) $ws->getCell('L'.$i)->getValue()) ?: null;

                continue;
            }

            $num = static fn (string $c) => is_numeric($v = $ws->getCell($c.$i)->getCalculatedValue()) ? (float) $v : null;

            if (is_numeric($b) && $e !== '' && ! str_starts_with(mb_strtolower($e), 'no realizaron')) {
                $bloques[$actual]['lineas'][] = [
                    'n' => (int) $b, 'cantidad' => $num('C'), 'unidad' => trim((string) $ws->getCell('D'.$i)->getValue()) ?: 'Pieza', 'descripcion' => preg_replace('/\s+/u', ' ', $e),
                    'precio' => $num('F'), 'ppt' => trim((string) $ws->getCell('H'.$i)->getValue()) ?: null, 'fin' => trim((string) $ws->getCell('I'.$i)->getValue()) ?: null,
                    'justificacion' => trim((string) $ws->getCell('J'.$i)->getValue()), 'l' => trim((string) $ws->getCell('L'.$i)->getValue()) ?: null,
                ];
            }
        }

        return $bloques;
    }

    private function usuario(string $rol, ?Estacion $estacion = null): User
    {
        $u = User::factory()->create(['estacion_id' => $estacion?->id]);
        $u->assignRole($rol);

        return $u;
    }

    private function xmlFactura(array $f): string
    {
        $subtotal = 0.0;
        $iva = 0.0;
        $nodos = '';

        foreach ($f['conceptos'] as $i => $c) {
            $importe = round($c['cantidad'] * $c['precio'], 2);
            $imp = round($importe * 0.08, 2);
            $subtotal += $importe;
            $iva += $imp;
            $nodos .= '<cfdi:Concepto ClaveProdServ="40141700" NoIdentificacion="S'.($i + 1).'" Cantidad="'.$c['cantidad'].'" ClaveUnidad="H87" Unidad="PIEZA" Descripcion="'.htmlspecialchars($c['descripcion']).'" ValorUnitario="'.$c['precio'].'" Importe="'.$importe.'" ObjetoImp="02">'
                .'<cfdi:Impuestos><cfdi:Traslados><cfdi:Traslado Base="'.$importe.'" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.080000" Importe="'.$imp.'"/></cfdi:Traslados></cfdi:Impuestos></cfdi:Concepto>';
        }

        $subtotal = round($subtotal, 2);
        $iva = round($iva, 2);
        $total = round($subtotal + $iva - $f['isr'], 2);
        $ret = $f['isr'] > 0 ? '<cfdi:Retenciones><cfdi:Retencion Impuesto="001" Importe="'.$f['isr'].'"/></cfdi:Retenciones>' : '';

        return '<?xml version="1.0" encoding="utf-8"?><cfdi:Comprobante xmlns:cfdi="http://www.sat.gob.mx/cfd/4" Version="4.0" Folio="'.$f['folio'].'" Fecha="'.$f['fecha'].'" SubTotal="'.$subtotal.'" Moneda="MXN" Total="'.$total.'" TipoDeComprobante="I" MetodoPago="PPD">'
            .'<cfdi:Emisor Rfc="'.$f['rfc'].'" Nombre="'.htmlspecialchars($f['emisor']).'" RegimenFiscal="601"/><cfdi:Receptor Rfc="TMA220603D80" Nombre="TREN MAYA" UsoCFDI="G03"/>'
            .'<cfdi:Conceptos>'.$nodos.'</cfdi:Conceptos><cfdi:Impuestos TotalImpuestosTrasladados="'.$iva.'">'.$ret.'<cfdi:Traslados><cfdi:Traslado Base="'.$subtotal.'" Impuesto="002" TipoFactor="Tasa" TasaOCuota="0.080000" Importe="'.$iva.'"/></cfdi:Traslados></cfdi:Impuestos>'
            .'<cfdi:Complemento><tfd:TimbreFiscalDigital xmlns:tfd="http://www.sat.gob.mx/TimbreFiscalDigital" Version="1.1" UUID="'.$f['uuid'].'" FechaTimbrado="'.$f['fecha'].'"/></cfdi:Complemento>'
            .($f['notas'] ? '<cfdi:Addenda><Notas>'.$f['notas'].'</Notas></cfdi:Addenda>' : '').'</cfdi:Comprobante>';
    }

    public function test_simulacion_septiembre(): void
    {
        set_time_limit(0);
        Storage::fake('local');
        @mkdir(self::SALIDA, 0777, true);
        $this->seed(RoleSeeder::class);
        $this->seed(EstacionSeeder::class);
        $estaciones = Estacion::query()->get()->keyBy('codigo');

        $this->nota('# Simulación del mes — Septiembre 2026');
        $this->nota('Datos: ANEXO enviado (1612), documento rectificado (1704) y COMPRA DE INSUMOS facturada, todos reales.');

        // ---------- 0. Preparación (una sola vez) ----------
        $servicioCatalogo = app(CatalogoInsumoService::class);
        $cargados = 0;
        foreach (['ZO_TJT_NÚM_0917_090626_ANEXO SE REMITE PROPUESTA DE INSUMOS_JUNIO.xlsx', 'ZO_TJT_NÚM_1612_080926_ANEXO SE REMITE PROPUESTA DE INSUMOS_SEP.xlsx', 'ZO_TJT_NÚM_1918_121026_ANEXO SE REMITE PROPUESTA DE INSUMOS_OCT.xlsx'] as $archivo) {
            $cargados += $servicioCatalogo->importarAnexo(self::BASE.$archivo)['leidos'];
        }
        Proveedor::factory()->create(['nombre' => 'Continental', 'rfc' => 'GFC051130D29', 'iva' => 8]);
        Proveedor::factory()->create(['nombre' => 'Erick Rojo Rumoroso', 'rfc' => 'RORE700101AB1', 'iva' => 8, 'retiene_isr' => true]);
        $this->nota("\n## 0. Preparación");
        $this->nota('- Catálogo cargado: '.CatalogoInsumo::query()->count().' insumos ('.$cargados.' renglones leídos de 3 ANEXOS).');

        // ---------- 1. Las estaciones capturan y envían ----------
        $this->nota("\n## 1. Las estaciones capturan su solicitud");
        $anexo = $this->bloques(self::BASE.'ZO_TJT_NÚM_1612_080926_ANEXO SE REMITE PROPUESTA DE INSUMOS_SEP.xlsx');
        $excedidas = [];

        foreach ($anexo as $bloque) {
            $estacion = $estaciones[$bloque['codigo']];
            $usuario = $this->usuario('Estación', $estacion);
            $this->actingAsTwoFactorVerified($usuario);
            $this->get(route('insumos.estacion', ['estacion' => $estacion->id, 'anio' => self::ANIO, 'mes' => self::MES]))->assertRedirect();
            $solicitud = SolicitudInsumo::query()->where('estacion_id', $estacion->id)->where('anio', self::ANIO)->where('mes', self::MES)->firstOrFail();

            $partidas = [];
            foreach (array_filter($bloque['lineas'], fn ($l) => $l['cantidad'] !== null && $l['cantidad'] > 0) as $l) {
                $cat = CatalogoInsumo::query()->where('descripcion', mb_substr($l['descripcion'], 0, 255))->first();
                $partidas[] = ['cantidad' => $l['cantidad'], 'unidad' => $l['unidad'], 'descripcion' => mb_substr($l['descripcion'], 0, 255), 'justificacion' => $l['justificacion'] ?: 'Mantenimiento', 'catalogo_insumo_id' => $cat?->id];
            }

            $this->put(route('insumos.update', $solicitud), ['tipo_servicio' => '1er escalón', 'lugar_trabajo' => $estacion->nombre, 'justificacion_general' => 'Simulación septiembre', 'solicitante_nombre' => 'Gerente de '.$estacion->nombre, 'solicitante_puesto' => 'Gerente de Estación', 'partidas' => $partidas])->assertSessionHasNoErrors();
            $respuesta = $this->post(route('insumos.enviar', $solicitud));

            if ($solicitud->fresh()->estado !== 'enviada') {
                $totales = app(SolicitudInsumoService::class)->totales($solicitud->fresh()->load('partidas'));
                $excedidas[$estacion->nombre] = $totales['total'];
                $this->nota(sprintf('- **%s**: %d partidas, total estimado con IVA $%s → **BLOQUEADA por el límite de $5,000**.', $estacion->nombre, count($partidas), number_format($totales['total'], 2)));
                config(['insumos.limite_estacion' => 999999]);
                $this->post(route('insumos.enviar', $solicitud));
                config(['insumos.limite_estacion' => 5000]);
            } else {
                $totales = app(SolicitudInsumoService::class)->totales($solicitud->fresh()->load('partidas'));
                $this->nota(sprintf('- %s: %d partidas, total estimado con IVA $%s → enviada.', $estacion->nombre, count($partidas), number_format($totales['total'], 2)));
            }
        }
        $this->nota(count($excedidas).' de '.count($anexo).' estaciones habrían quedado bloqueadas con el límite de $5,000 (para seguir la simulación se les levantó el límite).');

        $this->actingAsTwoFactorVerified($this->usuario('Jefe de Zona'));
        $this->capturar('01_tablero_enviadas', $this->get(route('insumos.index', ['anio' => self::ANIO, 'mes' => self::MES]))->getContent());
        $this->capturar('02_revisar_solicitud', $this->get(route('insumos.show', SolicitudInsumo::query()->where('estacion_id', $estaciones['31']->id)->firstOrFail()))->getContent());
        $this->capturar('11_proveedores', $this->get(route('insumos.proveedores.index'))->getContent());
        $this->capturar('12_catalogo', $this->get(route('insumos.catalogo.index'))->getContent());

        // ---------- 2. La Zona revisa y aprueba ----------
        $this->nota("\n## 2. La Zona completa precios y partidas y aprueba");
        $compra = self::BASE.'Fondo revolvente/Septiembre/COMPRA DE INSUMOS_SEP.xlsx';
        $facturado = $this->bloques($compra, 'FACTURADO');
        $precioReal = [];
        foreach ($facturado as $b) {
            foreach ($b['lineas'] as $l) {
                $precioReal[Str::ascii(mb_strtolower($l['descripcion']))] = ['precio' => $l['precio'], 'ppt' => $l['l']];
            }
        }

        $jefe = $this->usuario('Jefe de Zona');
        $this->actingAsTwoFactorVerified($jefe);
        $sinPrecio = 0;

        foreach ($anexo as $bloque) {
            $solicitud = SolicitudInsumo::query()->where('estacion_id', $estaciones[$bloque['codigo']]->id)->where('mes', self::MES)->firstOrFail();
            $lineas = array_values(array_filter($bloque['lineas'], fn ($l) => $l['cantidad'] !== null && $l['cantidad'] > 0));
            $datos = [];

            foreach ($solicitud->partidas()->orderBy('numero')->get() as $i => $partida) {
                $l = $lineas[$i] ?? [];
                $real = $precioReal[Str::ascii(mb_strtolower($partida->descripcion))] ?? null;
                $precio = ($l['precio'] ?? null) ?: ($partida->precio_unitario ?? null) ?: ($real['precio'] ?? null);

                if (! $precio) {
                    $sinPrecio++;
                    $precio = 1.00;
                }

                $datos[$partida->id] = ['precio_unitario' => $precio, 'partida_presupuestal' => ($l['fin'] ?? null) ?: ($l['ppt'] ?? null) ?: ($real['ppt'] ?? null) ?: '24901', 'proveedor' => 'Continental'];
            }

            $this->post(route('insumos.revisar', $solicitud), ['accion' => 'aprobar', 'partidas' => $datos])->assertSessionHasNoErrors();
        }
        $aprobadas = SolicitudInsumo::query()->where('estado', 'aprobada')->count();
        $this->capturar('03_tablero_aprobadas', $this->get(route('insumos.index', ['anio' => self::ANIO, 'mes' => self::MES]))->getContent());
        $this->nota("- Solicitudes aprobadas: {$aprobadas} de ".count($anexo).". Partidas a las que la Zona tuvo que ponerles precio a mano (no venían): {$sinPrecio}.");

        // ---------- 3. Consolidado ----------
        $this->nota("\n## 3. Consolidado (ANEXO, LISTADO y CONTROL)");
        foreach (['anexo', 'listado', 'control'] as $tipo) {
            $r = $this->get(route('insumos.consolidado', ['anio' => self::ANIO, 'mes' => self::MES, 'tipo' => $tipo, 'oficio' => '1612']))->assertOk();
            $nombre = ['anexo' => '1_ANEXO_para_la_Coordinacion', 'listado' => '2_LISTADO_POR_PRODUCTO', 'control' => '3_CONTROL_FILTRO'][$tipo].'.xlsx';
            file_put_contents(self::SALIDA.$nombre, $r->streamedContent());
        }
        $datos = app(ConsolidadoInsumosService::class)->datos(self::ANIO, self::MES);
        $alertas = app(ConsolidadoInsumosService::class)->alertas($datos);
        $this->nota(sprintf('- Total con IVA de la zona: $%s contra el fondo de $%s (pendiente $%s).', number_format($datos['total'], 2), number_format($datos['tope'], 2), number_format($datos['pendiente'], 2)));
        $this->nota('- Alertas en el CONTROL_FILTRO: '.count($alertas).' → '.collect($alertas)->pluck('alertas')->flatMap(fn ($a) => explode(', ', $a))->countBy()->map(fn ($n, $k) => "$k=$n")->implode(', '));

        // ---------- 4. Coordinación ----------
        $this->nota("\n## 4. Coordinación");
        $this->post(route('insumos.coordinacion.enviar'), ['anio' => self::ANIO, 'mes' => self::MES, 'oficio' => '1612'])->assertSessionHasNoErrors();
        $rectificado = self::BASE.'Fondo revolvente/Septiembre/ZO_TJT_NÚM_1704_110926_SE RECTIFICA PROPUESTA DE INSUMOS_SEP_v2.xlsx';
        $this->post(route('insumos.coordinacion.respuesta'), ['anio' => self::ANIO, 'mes' => self::MES, 'archivo' => new UploadedFile($rectificado, 'rectificado.xlsx', null, null, true)]);
        $res = session('resumen_coordinacion') ?? [];
        $this->nota('- Envío registrado con oficio 1612. Respuesta cargada (documento rectificado 1704).');
        $this->nota('- Solicitudes actualizadas: '.($res['solicitudes'] ?? 0).'; quitadas: '.count($res['quitadas'] ?? []).'; agregadas: '.count($res['agregadas'] ?? []).'; cambios de cantidad/precio: '.count($res['cambios'] ?? []).'.');
        $this->nota('- Bloques del archivo sin solicitud enviada: '.json_encode($res['sin_solicitud'] ?? [], JSON_UNESCAPED_UNICODE).'; sin renglones: '.json_encode($res['sin_renglones'] ?? [], JSON_UNESCAPED_UNICODE).'; estaciones enviadas que no aparecen: '.json_encode($res['sin_bloque'] ?? [], JSON_UNESCAPED_UNICODE));
        $this->nota('- Errores de la carga: '.json_encode(session('errors')?->all() ?? [], JSON_UNESCAPED_UNICODE));
        $this->nota('- Estados al terminar: '.SolicitudInsumo::query()->get()->countBy('estado')->map(fn ($n, $k) => "$k=$n")->implode(', '));
        $this->capturar('04_coordinacion', $this->get(route('insumos.coordinacion.index', ['anio' => self::ANIO, 'mes' => self::MES]))->getContent());

        // ---------- 5. Cotizaciones ----------
        $this->nota("\n## 5. Cotizaciones (proveedor Continental, folios 194 a 204)");
        $noFact = $this->bloques($compra, 'NO FACTURADO');
        $folios = ['23' => '194', '24' => '195', '25' => '196', '26' => '198', '27' => '199', '28' => '200', '29' => '201', '30' => '202', '31' => '203', '00' => '204'];
        $servicio = app(CotizacionService::class);
        $porEstacion = [];

        foreach ($facturado as $i => $b) {
            if ($i === 11) {
                continue; // la factura de la persona física lleva su propia cotización
            }
            foreach ($b['lineas'] as $l) {
                $porEstacion[$b['codigo']][] = $l + ['facturada' => true];
            }
        }
        foreach ($noFact as $b) {
            foreach ($b['lineas'] as $l) {
                $porEstacion[$b['codigo']][] = $l + ['facturada' => false];
            }
        }

        foreach ($porEstacion as $codigo => $lineas) {
            $partidas = $servicio->partidasDelMes(self::ANIO, self::MES)->filter(fn ($p) => $p->solicitud->estacion_id === $estaciones[$codigo]->id)->values();
            $sugeridas = $servicio->sugerirPartidas(array_map(fn ($l) => ['descripcion' => $l['descripcion']], $lineas), $partidas);
            $renglones = [];

            foreach ($lineas as $k => $l) {
                if (! $l['cantidad'] || ! $l['precio']) {
                    continue;
                }
                $precio = $l['precio'];
                if ($codigo === '25' && str_contains(mb_strtolower($l['descripcion']), 'amarillo')) {
                    $precio = round($precio / 1.109, 2); // el caso real de Tulum: cotizado 3,065.66 y facturado 3,398.73
                }
                $renglones[] = ['partida_id' => $sugeridas[$k], 'descripcion' => $l['descripcion'], 'cantidad' => $l['cantidad'], 'precio_unitario' => $precio, 'coincidencia' => 'mismo'];
            }

            $this->post(route('insumos.cotizaciones.store'), ['anio' => self::ANIO, 'mes' => self::MES, 'proveedor' => 'Continental', 'folio' => $folios[$codigo], 'fecha' => '2026-09-28', 'renglones' => $renglones])->assertSessionHasNoErrors();
        }
        $this->post(route('insumos.cotizaciones.store'), ['anio' => self::ANIO, 'mes' => self::MES, 'proveedor' => 'Erick Rojo Rumoroso', 'folio' => 'MIG-1', 'fecha' => '2026-09-25', 'renglones' => [['descripcion' => 'Rollo de cinta antiderrapante', 'cantidad' => 13, 'precio_unitario' => 339, 'coincidencia' => 'mismo']]])->assertSessionHasNoErrors();

        $resumenCot = $servicio->resumen(self::ANIO, self::MES);
        $this->capturar('05_cotizaciones_mes', $this->get(route('insumos.cotizaciones.index', ['anio' => self::ANIO, 'mes' => self::MES]))->getContent());
        $this->capturar('06_cotizacion_detalle', $this->get(route('insumos.cotizaciones.show', Cotizacion::query()->where('folio', '196')->firstOrFail()))->getContent());
        $this->nota(sprintf('- %d cotizaciones capturadas. Partidas actualizadas con precio cotizado: %d de %d; en REVISAR: %d; sin cotización: %d; cotizado fuera del documento: %d.', $resumenCot['cotizaciones'], $resumenCot['actualizadas'], $resumenCot['partidas'], $resumenCot['en_revision'], $resumenCot['sin_cotizacion'], $resumenCot['fuera_del_documento']));
        $r = $this->get(route('insumos.cotizaciones.descargar', ['anio' => self::ANIO, 'mes' => self::MES]))->assertOk();
        file_put_contents(self::SALIDA.'4_PRECIOS_REALES.xlsx', $r->streamedContent());
        $motivos = CotizacionRenglon::query()->where('estatus', 'revisar')->get()->countBy('motivo')->map(fn ($n, $k) => "$k=$n")->implode(', ');
        $this->nota('- Motivos de REVISAR: '.($motivos ?: 'ninguno'));

        // ---------- 6. Facturas (12, reconstruidas desde la COMPRA real) ----------
        $this->nota("\n## 6. Facturas (12 XML, uno por cada factura real)");
        $lector = new LectorXlsx;
        $lector->setReadDataOnly(false);
        $hojaFolios = $lector->load($compra)->getSheetByName('FOLIOS');
        $xmls = [];
        $totalesReales = [];

        for ($fila = 2; $fila <= 13; $fila++) {
            $i = $fila - 2;
            $folio = trim((string) $hojaFolios->getCell('E'.$fila)->getValue()) ?: 'MIG-001';
            $esMig = $i === 11;
            $codigo = $this->codigoDe((string) $hojaFolios->getCell('B'.$fila)->getValue());
            $numero = (int) preg_replace('/\D/', '', $folio);
            $xmls[] = $this->xmlFactura([
                'uuid' => strtoupper(trim((string) $hojaFolios->getCell('D'.$fila)->getValue())), 'folio' => $folio, 'fecha' => Date::excelToDateTimeObject((float) $hojaFolios->getCell('F'.$fila)->getValue())->format('Y-m-d\TH:i:s'),
                'rfc' => $esMig ? 'RORE700101AB1' : 'GFC051130D29', 'emisor' => $esMig ? 'ERICK ROJO RUMOROSO' : 'GRUPO FERRETERO CONTINENTAL', 'isr' => $esMig ? 55.09 : 0.0,
                'notas' => $esMig ? 'COT - 1 - MIG' : (in_array($i, [9, 10], true) ? null : 'COT - '.$folios[$codigo].' - RI - '.$numero),
                'conceptos' => array_map(fn ($l) => ['cantidad' => $l['cantidad'], 'precio' => $l['precio'], 'descripcion' => $l['descripcion']], array_values(array_filter($facturado[$i]['lineas'], fn ($l) => $l['cantidad'] && $l['precio']))),
            ]);
            $totalesReales[] = (float) $hojaFolios->getCell('G'.$fila)->getCalculatedValue();
        }

        $archivos = array_map(fn ($x, $k) => UploadedFile::fake()->createWithContent('factura_'.($k + 1).'.xml', $x), $xmls, array_keys($xmls));
        $pdfs = array_map(fn ($k) => UploadedFile::fake()->createWithContent('factura_'.($k + 1).'.pdf', "%PDF-1.4
"), array_keys($xmls));
        $this->post(route('insumos.facturas.store'), ['anio' => self::ANIO, 'mes' => self::MES, 'xml' => $archivos, 'pdf' => $pdfs])->assertSessionHasNoErrors();
        $resultados = session('resultados_facturas') ?? [];
        $this->nota('- Facturas cargadas: '.count(array_filter($resultados, fn ($r) => $r['ok'])).' de '.count($resultados).'. Fallas: '.json_encode(array_values(array_map(fn ($r) => $r['archivo'].': '.$r['mensaje'], array_filter($resultados, fn ($r) => ! $r['ok']))), JSON_UNESCAPED_UNICODE));

        $this->nota("\n| Factura | Estación | Total CFDI | Total real (FOLIOS) | Estado | Cotización | Hallazgos |\n|---|---|---|---|---|---|---|");
        foreach (Factura::query()->with(['estacion', 'cotizacion'])->orderBy('id')->get() as $k => $f) {
            $h = $f->hallazgos()->get()->where('tipo', '!=', 'OK')->map(fn ($x) => $x->tipo[0].':'.$x->codigo)->countBy()->map(fn ($n, $c) => $n > 1 ? "$c×$n" : $c)->implode(', ');
            $this->nota(sprintf('| %s | %s | $%s | $%s | %s | %s | %s |', $f->referencia(), $f->estacion?->nombre ?? '—', number_format((float) $f->total, 2), number_format($totalesReales[$k] ?? 0, 2), $f->estado, $f->cotizacion ? $f->cotizacion->folio.' ('.$f->vinculo_cotizacion.')' : 'sin cotización', $h ?: 'todo OK'));
        }

        $resumenFac = app(ComprobacionService::class)->resumen(self::ANIO, self::MES);
        $this->capturar('08_facturas_mes', $this->get(route('insumos.facturas.index', ['anio' => self::ANIO, 'mes' => self::MES]))->getContent());
        $this->capturar('09_factura_detalle', $this->get(route('insumos.facturas.show', Factura::query()->where('folio', '000144RI')->firstOrFail()))->getContent());
        $this->capturar('10_factura_ok', $this->get(route('insumos.facturas.show', Factura::query()->where('folio', '000135RI')->firstOrFail()))->getContent());
        $this->nota(sprintf("\n- Total facturado: $%s (real: $%s). Fondo $%s, pendiente $%s. Hallazgos: %s. No facturado (sin IVA): $%s.", number_format($resumenFac['total'], 2), number_format(array_sum($totalesReales), 2), number_format($resumenFac['fondo'], 2), number_format($resumenFac['pendiente'], 2), json_encode($resumenFac['hallazgos']), number_format($resumenFac['no_facturado'], 2)));

        foreach (['compra' => '5_COMPRA_DE_INSUMOS', 'reporte' => '6_REPORTE_COMPROBACION'] as $tipo => $nombre) {
            file_put_contents(self::SALIDA.$nombre.'.xlsx', $this->get(route('insumos.facturas.descargar', ['anio' => self::ANIO, 'mes' => self::MES, 'tipo' => $tipo]))->assertOk()->streamedContent());
        }

        $this->nota("\n## Archivos generados en ".self::SALIDA);
        foreach (glob(self::SALIDA.'*.xlsx') as $a) {
            $this->nota('- '.basename($a));
        }

        file_put_contents(self::SALIDA.'informe_simulacion.md', implode("\n", $this->informe));
        $this->assertTrue(true);
        $this->assertNotNull(IOFactory::class);
    }
}
