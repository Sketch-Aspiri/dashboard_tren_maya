<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Libro de la fase A del fondo rotatorio: el ANEXO con precios reales (hoja
 * ZO_Cotización con las columnas M a P de cotización) y las hojas de reporte
 * del antiguo Flujo 2A: REPORTE_COTIZACIONES, Actualizados, REVISAR,
 * Cotizado fuera del documento y Cotizaciones. Todo texto capturado se
 * escribe como cadena explícita, nunca como fórmula.
 */
final class CotizacionesLibro
{
    private const DINERO = '"$"#,##0.00';

    private const GRIS = 'D0D0D0';

    public function __construct(private readonly ConsolidadoInsumosLibro $anexo) {}

    /**
     * @param  array<string, mixed>  $datos  Salida de ConsolidadoInsumosService::datos().
     * @param  array<string, mixed>  $resumen  Salida de CotizacionService::resumen().
     * @param  array<string, mixed>  $reporte  Salida de CotizacionService::reporte().
     */
    public function generar(array $datos, array $resumen, array $reporte): Spreadsheet
    {
        $libro = $this->anexo->anexo($datos, $reporte['anotaciones']);

        $this->resumen($libro->createSheet(), $datos, $resumen);
        $this->tabla($libro->createSheet(), 'Actualizados', ['Estación', 'Descripción', 'P.U. estimado', 'P.U. cotizado', 'Variación %', 'Cotización'], $reporte['actualizados'],
            ['estacion', 'descripcion', 'estimado', 'cotizado', 'variacion', 'cotizacion'], [24, 52, 14, 14, 12, 28], ['C', 'D']);
        $this->tabla($libro->createSheet(), 'REVISAR', ['Estación', 'Descripción', 'Tipo', 'Detalle', 'Cotización'], $reporte['revisar'],
            ['estacion', 'descripcion', 'tipo', 'detalle', 'cotizacion'], [24, 52, 28, 60, 28]);
        $this->tabla($libro->createSheet(), 'Cotizado fuera del documento', ['Descripción cotizada', 'Cantidad', 'Precio', 'Cotización'], $reporte['fuera'],
            ['descripcion', 'cantidad', 'precio', 'cotizacion'], [60, 10, 14, 28], ['C']);
        $this->tabla($libro->createSheet(), 'Sin cotización', ['Estación', 'Descripción', 'Cantidad'], $reporte['sin_cotizacion'],
            ['estacion', 'descripcion', 'cantidad'], [24, 60, 10]);
        $this->tabla($libro->createSheet(), 'Cotizaciones', ['Proveedor', 'Folio', 'Fecha', 'Renglones', 'Subtotal', 'IVA', 'Total', 'Validación', 'Detalle'], $reporte['cotizaciones'],
            ['proveedor', 'folio', 'fecha', 'renglones', 'subtotal', 'iva', 'total', 'validacion', 'detalle'], [28, 16, 12, 10, 14, 14, 14, 12, 70], ['E', 'F', 'G']);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $resumen
     */
    private function resumen(Worksheet $hoja, array $datos, array $resumen): void
    {
        $hoja->setTitle('REPORTE_COTIZACIONES');
        $hoja->getColumnDimension('A')->setWidth(46);
        $hoja->getColumnDimension('B')->setWidth(20);
        $this->cabecera($hoja, 1, ['Concepto', 'Valor']);

        $filas = [
            ['Mes', $datos['mesNombre'].' '.$datos['anio']],
            ['Partidas solicitadas (aprobadas)', $resumen['partidas']],
            ['Partidas ACTUALIZADAS con precio cotizado', $resumen['actualizadas']],
            ['Partidas en REVISAR', $resumen['en_revision']],
            ['Partidas sin cotización', $resumen['sin_cotizacion']],
            ['Renglones cotizados fuera del documento', $resumen['fuera_del_documento']],
            ['Cotizaciones capturadas', $resumen['cotizaciones']],
            ['Cotizaciones con problemas de aritmética', $resumen['con_problemas']],
            ['Subtotal estimado', $resumen['subtotal_estimado']],
            ['Subtotal con precios reales', $resumen['subtotal_real']],
            ['Total con IVA (precios reales)', $resumen['total_con_iva']],
            ['Fondo rotatorio', $resumen['fondo']],
            ['Verificación del tope', $resumen['excede'] ? 'EXCEDE EL TOPE' : 'DENTRO DEL TOPE'],
        ];

        foreach ($filas as $i => [$concepto, $valor]) {
            $this->texto($hoja, 'A'.($i + 2), $concepto);
            is_string($valor) ? $this->texto($hoja, 'B'.($i + 2), $valor) : $hoja->setCellValue('B'.($i + 2), $valor);
        }

        $hoja->getStyle('B10:B13')->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * @param  list<string>  $titulos
     * @param  list<array<string, mixed>>  $filas
     * @param  list<string>  $claves  Clave de cada columna en $filas.
     * @param  list<int>  $anchos
     * @param  list<string>  $dinero  Columnas con formato de dinero.
     */
    private function tabla(Worksheet $hoja, string $nombre, array $titulos, array $filas, array $claves, array $anchos, array $dinero = []): void
    {
        $hoja->setTitle($nombre);

        foreach ($anchos as $i => $ancho) {
            $hoja->getColumnDimension(chr(65 + $i))->setWidth($ancho);
        }

        $this->cabecera($hoja, 1, $titulos);

        foreach ($filas as $i => $fila) {
            foreach ($claves as $j => $clave) {
                $celda = chr(65 + $j).($i + 2);
                $valor = $fila[$clave] ?? null;

                if (is_int($valor) || is_float($valor)) {
                    $hoja->setCellValue($celda, $valor);
                } else {
                    $this->texto($hoja, $celda, $valor);
                }
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
    private function cabecera(Worksheet $hoja, int $fila, array $titulos): void
    {
        foreach ($titulos as $i => $titulo) {
            $this->texto($hoja, chr(65 + $i).$fila, $titulo);
        }

        $rango = 'A'.$fila.':'.chr(64 + count($titulos)).$fila;
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
}
