<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Models\FacturaHallazgo;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Libros de Excel de la fase B del fondo rotatorio (antes el Flujo 2B):
 * COMPRA DE INSUMOS (hojas FACTURADO, NO FACTURADO y FOLIOS, con fórmulas) y
 * REPORTE_COMPROBACION (resumen, hallazgos y cuadros por factura y estación).
 * Todo texto que viene de las facturas se escribe como cadena explícita,
 * nunca como fórmula.
 */
final class FacturasLibro
{
    private const DINERO = '"$"#,##0.00';

    private const GRIS = 'D0D0D0';

    /** @var array<string, string> Denominación de cada partida presupuestal. */
    private array $denominaciones = [];

    /**
     * @param  Collection<int, Factura>  $facturas  Con conceptos.partida.solicitud.estacion y estacion.
     * @param  list<array<string, mixed>>  $noFacturado  Salida de ComprobacionService::noFacturado().
     */
    public function compra(int $anio, int $mes, Collection $facturas, array $noFacturado): Spreadsheet
    {
        $libro = new Spreadsheet;
        $facturado = $libro->getActiveSheet()->setTitle('FACTURADO');
        $celdasTotal = [];
        $filasTotal = $this->hojaFacturado($facturado, $anio, $mes, $facturas, $celdasTotal);

        $this->hojaNoFacturado($libro->createSheet(), $anio, $mes, $noFacturado);
        $this->hojaFolios($libro->createSheet(), $facturas, $celdasTotal);

        $facturado->setCellValue('K3', $filasTotal === [] ? 0 : '=SUM('.implode(',', $filasTotal).')');
        $this->horizontal($facturado);
        $this->horizontal($libro->getSheetByName('NO FACTURADO'));
        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * @param  Collection<int, Factura>  $facturas
     * @param  array<string, mixed>  $resumen  Salida de ComprobacionService::resumen().
     * @param  list<array<string, mixed>>  $noFacturado
     * @param  list<Cotizacion>  $cotizacionesSinFactura
     */
    public function reporte(int $anio, int $mes, Collection $facturas, array $resumen, array $noFacturado, array $cotizacionesSinFactura): Spreadsheet
    {
        $libro = new Spreadsheet;
        $this->resumen($libro->getActiveSheet(), $anio, $mes, $resumen);

        $hallazgos = FacturaHallazgo::query()->with('concepto')->whereIn('factura_id', $facturas->pluck('id'))->get()->groupBy('factura_id');
        $filas = [];

        foreach ($facturas as $factura) {
            foreach ($hallazgos->get($factura->id, []) as $h) {
                $filas[] = [
                    $factura->estacion?->nombre, $factura->referencia(), $factura->cotizacion ? $factura->cotizacion->proveedor.' '.$factura->cotizacion->folio : null,
                    $h->concepto?->numero, $h->tipo, $h->codigo.($h->detalle ? ': '.$h->detalle : ''), $h->valor_cotizado === null ? null : (float) $h->valor_cotizado,
                    $h->valor_facturado === null ? null : (float) $h->valor_facturado, $h->diferencia === null ? null : (float) $h->diferencia,
                ];
            }
        }

        $this->tabla($libro->createSheet(), 'Hallazgos', ['Estación', 'Factura', 'Cotización', 'Renglón', 'Tipo', 'Detalle', 'Valor cotizado', 'Valor facturado', 'Diferencia'], $filas, [24, 16, 24, 9, 10, 80, 14, 14, 14], ['G', 'H', 'I']);
        $this->tabla($libro->createSheet(), 'Por factura', ['UUID', 'Emisor', 'RFC', 'Factura', 'Cotización', 'Cómo se vinculó', 'Subtotal', 'IVA', 'ISR retenido', 'Total', 'OK', 'ALERTA', 'ERROR', 'REVISAR', 'Estado'],
            $facturas->map(function (Factura $f) use ($hallazgos) {
                $conteo = collect($hallazgos->get($f->id, []))->countBy('tipo');

                return [$f->uuid, $f->emisor_nombre, $f->emisor_rfc, $f->referencia(), $f->cotizacion ? $f->cotizacion->proveedor.' '.$f->cotizacion->folio : null, $f->vinculo_cotizacion,
                    (float) $f->subtotal, (float) $f->iva, (float) $f->isr_retenido, (float) $f->total, (int) $conteo->get('OK', 0), (int) $conteo->get('ALERTA', 0), (int) $conteo->get('ERROR', 0), (int) $conteo->get('REVISAR', 0), $f->estado];
            })->all(), [38, 30, 15, 14, 24, 14, 13, 13, 13, 13, 6, 8, 8, 9, 10], ['G', 'H', 'I', 'J']);

        $this->tabla($libro->createSheet(), 'Por estación', ['Estación', 'Facturas', 'Total facturado', 'OK', 'ALERTA', 'ERROR', 'REVISAR', 'Importe no facturado'],
            $this->porEstacion($facturas, $hallazgos, $noFacturado), [26, 10, 16, 6, 8, 8, 9, 18], ['C', 'H']);

        $sinVinculo = [];

        foreach ($facturas as $f) {
            if ($f->cotizacion_id === null) {
                $sinVinculo[] = ['Factura sin cotización', $f->referencia().' '.$f->emisor_nombre, null];
            } elseif ($f->vinculo_cotizacion === 'contenido') {
                $sinVinculo[] = ['Vínculo de confianza baja', $f->referencia().' → '.$f->cotizacion->proveedor.' '.$f->cotizacion->folio, 'Solo por parecido de contenido'];
            }
        }

        foreach ($cotizacionesSinFactura as $c) {
            $sinVinculo[] = ['Cotización sin factura', $c->proveedor.' '.$c->folio, null];
        }

        $this->tabla($libro->createSheet(), 'Sin vínculo', ['Tipo', 'Detalle', 'Nota'], $sinVinculo, [30, 70, 34]);

        // Solo informativo: no se valida que la factura sea posterior a la cotización.
        $this->tabla($libro->createSheet(), 'Fechas (solo informativo)', ['Factura', 'Fecha de factura', 'Cotización', 'Fecha de cotización'],
            $facturas->map(fn (Factura $f) => [$f->referencia(), $f->fecha->format('d/m/Y'), $f->cotizacion ? $f->cotizacion->proveedor.' '.$f->cotizacion->folio : null, $f->cotizacion?->fecha?->format('d/m/Y')])->all(),
            [18, 16, 28, 18]);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    // --- COMPRA DE INSUMOS --------------------------------------------------------------

    /**
     * @param  Collection<int, Factura>  $facturas
     * @param  array<int, string>  $celdasTotal  Celda del TOTAL de cada factura, por id (para FOLIOS).
     * @return list<string> Celdas de TOTAL de todos los bloques.
     */
    private function hojaFacturado(Worksheet $hoja, int $anio, int $mes, Collection $facturas, array &$celdasTotal): array
    {
        $this->anchos($hoja, ['A' => 3.7, 'B' => 5.5, 'C' => 7, 'D' => 10, 'E' => 44, 'F' => 13, 'G' => 14, 'H' => 11, 'I' => 12, 'J' => 38, 'K' => 26, 'L' => 14, 'M' => 12, 'N' => 14, 'O' => 14, 'P' => 14]);
        $this->encabezado($hoja, $mes);

        $fila = 7;
        $totales = [];

        foreach ($facturas as $factura) {
            $fila = $this->bloqueFactura($hoja, $fila, $factura, $celdasTotal, $totales);
        }

        return $totales;
    }

    /**
     * @param  array<int, string>  $celdasTotal
     * @param  list<string>  $totales
     */
    private function bloqueFactura(Worksheet $hoja, int $fila, Factura $factura, array &$celdasTotal, array &$totales): int
    {
        $estacion = $factura->estacion;
        $solicitud = $factura->conceptos->first(fn (FacturaConcepto $c) => $c->partida)?->partida->solicitud;

        foreach ([$fila, $fila + 1] as $f) {
            $hoja->mergeCells('B'.$f.':K'.$f);
            $hoja->getStyle('B'.$f.':K'.$f)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
            $hoja->getStyle('B'.$f)->getFont()->setBold(true);
        }

        $this->texto($hoja, 'B'.$fila, $estacion ? ($estacion->codigo ?? '').'_'.mb_strtoupper($estacion->nombre) : 'SIN ESTACIÓN');
        $this->texto($hoja, 'B'.($fila + 1), $solicitud?->solicitante_nombre ? $solicitud->solicitante_nombre.($solicitud->solicitante_puesto ? ', '.$solicitud->solicitante_puesto : '') : $factura->emisor_nombre);

        $cabecera = $fila + 2;
        $this->cabecera($hoja, $cabecera, ['N°', 'Cant', 'Unidad', 'Descripción', 'P.U.', 'Precio total', 'P.Ptal', 'P.Ptal/Fin', 'Observaciones/Justificación de insumos', 'Proveedor', 'Partida presupuestal'], 'B');
        $this->cabecera($hoja, $cabecera, ['PPTAL', 'SUBTOTAL', 'IVA', 'TOTAL'], 'M');

        $primera = $cabecera + 1;
        $fila = $primera;
        $filasPorTasa = [];
        $filasPorPartida = [];

        foreach ($factura->conceptos as $concepto) {
            $partida = $concepto->partida;
            $final = $partida?->partida_final ?? $partida?->partida_presupuestal;

            $hoja->setCellValue('B'.$fila, $concepto->numero);
            $hoja->setCellValue('C'.$fila, (float) $concepto->cantidad);
            $this->texto($hoja, 'D'.$fila, $partida?->unidad ?? $concepto->unidad);
            $this->texto($hoja, 'E'.$fila, $partida?->descripcion ?? $concepto->descripcion);
            $hoja->setCellValue('F'.$fila, (float) $concepto->valor_unitario);
            $hoja->setCellValue('G'.$fila, '=C'.$fila.'*F'.$fila);
            $this->texto($hoja, 'H'.$fila, $partida?->partida_presupuestal);
            $this->texto($hoja, 'I'.$fila, $final);
            $this->texto($hoja, 'J'.$fila, $partida?->justificacion);
            $this->texto($hoja, 'K'.$fila, $factura->emisor_nombre);
            $this->texto($hoja, 'L'.$fila, $final);

            $filasPorTasa[(string) ($concepto->iva_tasa === null ? 0 : (float) $concepto->iva_tasa)][] = $fila;
            $filasPorPartida[$final ?? 'SIN PARTIDA'][] = $fila;
            $fila++;
        }

        $ultima = $fila - 1;
        $hoja->getStyle('F'.$primera.':G'.$ultima)->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('B'.$primera.':L'.$ultima)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $subtotal = $fila;
        $hoja->setCellValue('E'.$subtotal, 'Subtotal');
        $hoja->setCellValue('G'.$subtotal, '=SUM(G'.$primera.':G'.$ultima.')');
        $this->texto($hoja, 'L'.$subtotal, $factura->referencia());
        $fila = $this->filasDeImpuestos($hoja, $factura, $subtotal, $filasPorTasa);

        $filaTotal = $fila + 1;
        $hoja->setCellValue('E'.$filaTotal, 'TOTAL ');
        $hoja->setCellValue('G'.$filaTotal, '=SUM(G'.$subtotal.':G'.$fila.')');
        $this->texto($hoja, 'M'.$filaTotal, $factura->referencia());
        $hoja->getStyle('G'.$subtotal.':G'.$filaTotal)->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('E'.$filaTotal.':G'.$filaTotal)->getFont()->setBold(true);
        $this->bordes($hoja, 'B'.$cabecera.':L'.$filaTotal);

        $this->resumenPorPartida($hoja, $cabecera + 1, $factura, $filasPorPartida);

        $celdasTotal[$factura->id] = 'G'.$filaTotal;
        $totales[] = 'G'.$filaTotal;

        return $filaTotal + 3;
    }

    /**
     * IVA por tasa (el importe de la factura), descuento y retenciones. Devuelve la última fila usada.
     *
     * @param  array<string, list<int>>  $filasPorTasa
     */
    private function filasDeImpuestos(Worksheet $hoja, Factura $factura, int $subtotal, array $filasPorTasa): int
    {
        $fila = $subtotal;
        $conDescuento = (float) $factura->descuento > 0;

        if ($conDescuento) {
            $fila++;
            $this->texto($hoja, 'E'.$fila, 'Descuento');
            $hoja->setCellValue('G'.$fila, -(float) $factura->descuento);
        }

        ksort($filasPorTasa);

        foreach ($filasPorTasa as $tasa => $filas) {
            $fila++;
            $this->texto($hoja, 'E'.$fila, 'IVA '.rtrim(rtrim(number_format((float) $tasa, 2, '.', ''), '0'), '.').' %');

            // El IVA es el que trae la factura (suma de sus conceptos): recalcularlo puede
            // diferir un centavo y el total de FOLIOS debe coincidir con el CFDI.
            $hoja->setCellValue('G'.$fila, round((float) $factura->conceptos->filter(fn ($c) => (string) ($c->iva_tasa === null ? 0 : (float) $c->iva_tasa) === (string) $tasa)->sum('iva_importe'), 2));
        }

        foreach ([['ISR retenido', (float) $factura->isr_retenido], ['Otras retenciones', (float) $factura->otros_retenidos]] as [$etiqueta, $monto]) {
            if ($monto > 0) {
                $fila++;
                $this->texto($hoja, 'E'.$fila, $etiqueta);
                $hoja->setCellValue('G'.$fila, -$monto);
            }
        }

        return $fila;
    }

    /**
     * Columnas M a P junto al bloque: subtotal, IVA y total por partida.
     *
     * @param  array<string, list<int>>  $filasPorPartida
     */
    private function resumenPorPartida(Worksheet $hoja, int $fila, Factura $factura, array $filasPorPartida): void
    {
        $tasas = $factura->conceptos->mapWithKeys(fn (FacturaConcepto $c) => [$c->numero => $c->iva_tasa === null ? 0.0 : (float) $c->iva_tasa]);

        foreach ($filasPorPartida as $partida => $filas) {
            $this->texto($hoja, 'M'.$fila, $partida);
            $hoja->setCellValue('N'.$fila, '='.implode('+', array_map(fn (int $f) => 'G'.$f, $filas)));

            $iva = array_map(fn (int $f) => rtrim(rtrim(number_format((float) ($tasas[(int) $hoja->getCell('B'.$f)->getValue()] ?? 0), 2, '.', ''), '0'), '.').'%*G'.$f, $filas);
            $hoja->setCellValue('O'.$fila, '='.implode('+', $iva));
            $hoja->setCellValue('P'.$fila, '=N'.$fila.'+O'.$fila);
            $hoja->getStyle('N'.$fila.':P'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);
            $fila++;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $noFacturado
     */
    private function hojaNoFacturado(Worksheet $hoja, int $anio, int $mes, array $noFacturado): void
    {
        $hoja->setTitle('NO FACTURADO');
        $this->anchos($hoja, ['A' => 3.7, 'B' => 5.5, 'C' => 7, 'D' => 10, 'E' => 44, 'F' => 13, 'G' => 14, 'H' => 11, 'I' => 12, 'J' => 38, 'K' => 22, 'L' => 44]);
        $this->encabezado($hoja, $mes);

        $fila = 7;

        foreach (collect($noFacturado)->groupBy('estacion') as $estacion => $filas) {
            $hoja->mergeCells('B'.$fila.':K'.$fila);
            $hoja->getStyle('B'.$fila.':K'.$fila)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
            $this->texto($hoja, 'B'.$fila, $estacion);
            $hoja->getStyle('B'.$fila)->getFont()->setBold(true);
            $cabecera = $fila + 1;
            $this->cabecera($hoja, $cabecera, ['N°', 'Cant', 'Unidad', 'Descripción', 'P.U.', 'Precio total', 'P.Ptal', 'P.Ptal/Fin', 'Observaciones/Justificación de insumos', 'Proveedor', 'Motivo'], 'B');
            $fila = $cabecera + 1;
            $primera = $fila;

            foreach ($filas->values() as $i => $item) {
                $hoja->setCellValue('B'.$fila, $i + 1);
                $hoja->setCellValue('C'.$fila, $item['cantidad']);
                $this->texto($hoja, 'D'.$fila, $item['unidad']);
                $this->texto($hoja, 'E'.$fila, $item['descripcion']);
                $hoja->setCellValue('F'.$fila, $item['precio']);
                $hoja->setCellValue('G'.$fila, '=C'.$fila.'*F'.$fila);
                $this->texto($hoja, 'J'.$fila, $item['justificacion']);
                $this->texto($hoja, 'K'.$fila, $item['proveedor']);
                $this->texto($hoja, 'L'.$fila, $item['motivo']);
                $fila++;
            }

            $hoja->getStyle('F'.$primera.':G'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);
            $hoja->getStyle('B'.$primera.':L'.($fila - 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $this->texto($hoja, 'E'.$fila, 'Subtotal');
            $hoja->setCellValue('G'.$fila, '=SUM(G'.$primera.':G'.($fila - 1).')');
            $hoja->getStyle('E'.$fila.':G'.$fila)->getFont()->setBold(true);
            $this->bordes($hoja, 'B'.$cabecera.':L'.$fila);
            $fila += 3;
        }

        $hoja->setCellValue('K3', round((float) collect($noFacturado)->sum('importe'), 2));
    }

    /**
     * @param  Collection<int, Factura>  $facturas
     * @param  array<int, string>  $celdasTotal
     */
    private function hojaFolios(Worksheet $hoja, Collection $facturas, array $celdasTotal): void
    {
        $hoja->setTitle('FOLIOS');
        $this->anchos($hoja, ['A' => 3, 'B' => 28, 'C' => 38, 'D' => 40, 'E' => 14, 'F' => 12, 'G' => 14, 'H' => 60, 'I' => 60]);
        $this->cabecera($hoja, 1, ['ESTACIÓN', 'Proveedor', 'FF', 'CODIGO', 'FECHA', 'TOTAL', 'Letras', 'PPTAL'], 'B');

        $fila = 2;

        foreach ($facturas as $factura) {
            $estacion = $factura->estacion;
            $this->texto($hoja, 'B'.$fila, $estacion ? ($estacion->codigo ?? '').'_'.$estacion->nombre : null);
            $this->texto($hoja, 'C'.$fila, $factura->emisor_nombre);
            $this->texto($hoja, 'D'.$fila, $factura->uuid);
            $this->texto($hoja, 'E'.$fila, $factura->referencia());
            $this->texto($hoja, 'F'.$fila, $factura->fecha->format('d/m/Y'));
            $hoja->setCellValue('G'.$fila, "=FACTURADO!{$celdasTotal[$factura->id]}");
            $this->texto($hoja, 'H'.$fila, ImporteConLetra::convertir((float) $factura->total));
            $this->texto($hoja, 'I'.$fila, $this->partidasDeFactura($factura));
            $hoja->getStyle('B'.$fila.':I'.$fila)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
            $fila++;
        }

        $this->texto($hoja, 'F'.$fila, 'TOTAL');
        $hoja->setCellValue('G'.$fila, $facturas->isEmpty() ? 0 : '=SUM(G2:G'.($fila - 1).')');
        $hoja->getStyle('F'.$fila.':G'.$fila)->getFont()->setBold(true);
        $hoja->getStyle('G2:G'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * Una línea por partida de la factura: 24801 “Materiales complementarios”.
     */
    private function partidasDeFactura(Factura $factura): string
    {
        $denominaciones = $this->denominaciones();

        return $factura->conceptos->map(fn (FacturaConcepto $c) => $c->partida?->partida_final ?? $c->partida?->partida_presupuestal)
            ->filter()->unique()->map(fn (string $codigo) => $codigo.' “'.($denominaciones[$codigo] ?? 'sin denominación').'”')->implode("\n");
    }

    /**
     * @return array<string, string>
     */
    private function denominaciones(): array
    {
        return (new DenominacionPartidas)->todas();
    }

    // --- REPORTE_COMPROBACION ---------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $resumen
     */
    private function resumen(Worksheet $hoja, int $anio, int $mes, array $resumen): void
    {
        $hoja->setTitle('Resumen');
        $this->anchos($hoja, ['A' => 44, 'B' => 20]);
        $this->cabecera($hoja, 1, ['Concepto', 'Valor'], 'A');

        $filas = [
            ['Mes', ConsolidadoInsumosService::MESES[$mes].' '.$anio],
            ['Facturas leídas', $resumen['facturas']],
            ['Total facturado', $resumen['total']],
            ['Fondo rotatorio', $resumen['fondo']],
            ['Pendiente por ejercer', $resumen['pendiente']],
            ['Verificación del tope', $resumen['excede'] ? 'ERROR: EXCEDE EL FONDO' : 'DENTRO DEL FONDO'],
            ['Hallazgos OK', $resumen['hallazgos']['OK']],
            ['Hallazgos ALERTA', $resumen['hallazgos']['ALERTA']],
            ['Hallazgos ERROR', $resumen['hallazgos']['ERROR']],
            ['Hallazgos REVISAR', $resumen['hallazgos']['REVISAR']],
            ['Importe no facturado (sin IVA)', $resumen['no_facturado']],
            ['Verificación SAT', $resumen['sat']],
        ];

        foreach ($filas as $i => [$concepto, $valor]) {
            $this->texto($hoja, 'A'.($i + 2), $concepto);
            is_string($valor) ? $this->texto($hoja, 'B'.($i + 2), $valor) : $hoja->setCellValue('B'.($i + 2), $valor);
        }

        $hoja->getStyle('B4:B6')->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('B12')->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * @param  Collection<int, Factura>  $facturas
     * @param  Collection<int|string, Collection<int, FacturaHallazgo>>  $hallazgos
     * @param  list<array<string, mixed>>  $noFacturado
     * @return list<list<mixed>>
     */
    private function porEstacion(Collection $facturas, Collection $hallazgos, array $noFacturado): array
    {
        $sinFacturar = collect($noFacturado)->groupBy('estacion')->map(fn ($f) => round((float) $f->sum('importe'), 2));

        return $facturas->groupBy(fn (Factura $f) => $f->estacion?->nombre ?? 'Sin estación')->map(function (Collection $grupo, string $nombre) use ($hallazgos, $sinFacturar) {
            $conteo = $grupo->flatMap(fn (Factura $f) => $hallazgos->get($f->id, []))->countBy('tipo');

            return [$nombre, $grupo->count(), round((float) $grupo->sum('total'), 2), (int) $conteo->get('OK', 0), (int) $conteo->get('ALERTA', 0), (int) $conteo->get('ERROR', 0), (int) $conteo->get('REVISAR', 0), (float) ($sinFacturar[$nombre] ?? 0)];
        })->values()->all();
    }

    // --- Utilidades de hoja -----------------------------------------------------------------

    private function encabezado(Worksheet $hoja, int $mes): void
    {
        foreach ([2 => 'COORDINACIÓN GENERAL DE GESTIÓN DE INFRAESTRUCTURA FERROVIARIA', 3 => 'DIRECCIÓN DE GESTIÓN TERRITORIAL ZONA ORIENTE', 4 => 'SOLICITUD DE INSUMOS PARA MANTENIMIENTO A ESTACIONES', 5 => 'INSUMOS REQUERIDOS DEL MES: '.mb_strtoupper(ConsolidadoInsumosService::MESES[$mes])] as $fila => $texto) {
            $hoja->mergeCells('E'.$fila.':I'.$fila);
            $this->texto($hoja, 'E'.$fila, $texto);
            $hoja->getStyle('E'.$fila)->getFont()->setBold(true);
        }

        $this->texto($hoja, 'J2', 'TOTAL FONDO ROTATORIO');
        $hoja->setCellValue('K2', (float) config('insumos.fondo_zona'));
        $this->texto($hoja, 'J3', 'TOTAL ');
        $this->texto($hoja, 'J4', 'TOTAL PEND. POR EJERCER');
        $hoja->setCellValue('K4', '=K2-K3');
        $hoja->getStyle('J2:K4')->getFont()->setBold(true);
        $hoja->getStyle('K2:K4')->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * @param  list<string>  $titulos
     * @param  list<list<mixed>>  $filas
     * @param  list<int>  $anchos
     * @param  list<string>  $dinero
     */
    private function tabla(Worksheet $hoja, string $nombre, array $titulos, array $filas, array $anchos, array $dinero = []): void
    {
        $hoja->setTitle($nombre);
        $this->anchos($hoja, array_combine(array_map(fn (int $i) => chr(65 + $i), array_keys($anchos)), $anchos));
        $this->cabecera($hoja, 1, $titulos, 'A');

        foreach ($filas as $i => $fila) {
            foreach (array_values($fila) as $j => $valor) {
                $celda = chr(65 + $j).($i + 2);
                is_int($valor) || is_float($valor) ? $hoja->setCellValue($celda, $valor) : $this->texto($hoja, $celda, $valor);
            }
        }

        $ultima = max(2, count($filas) + 1);

        foreach ($dinero as $columna) {
            $hoja->getStyle($columna.'2:'.$columna.$ultima)->getNumberFormat()->setFormatCode(self::DINERO);
        }

        $hoja->getStyle('A2:'.chr(64 + count($titulos)).$ultima)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * @param  list<string>  $titulos
     */
    private function cabecera(Worksheet $hoja, int $fila, array $titulos, string $columnaInicial): void
    {
        $columna = $columnaInicial;

        foreach ($titulos as $titulo) {
            $this->texto($hoja, $columna.$fila, $titulo);
            $columna++;
        }

        $rango = $columnaInicial.$fila.':'.chr(ord($columnaInicial) + count($titulos) - 1).$fila;
        $hoja->getStyle($rango)->getFont()->setBold(true);
        $hoja->getStyle($rango)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
        $hoja->getStyle($rango)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
    }

    private function texto(Worksheet $hoja, string $celda, mixed $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    }

    /**
     * @param  array<string, float>  $anchos
     */
    private function anchos(Worksheet $hoja, array $anchos): void
    {
        foreach ($anchos as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
    }

    private function horizontal(Worksheet $hoja): void
    {
        $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    }

    private function bordes(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }
}
