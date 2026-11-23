<?php

namespace App\Services\Agua;

use App\Models\AguaRegistro;
use App\Models\Estacion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * Excel mensual del registro de agua con el mismo contenido del control original (programa de la estación,
 * PRIMER..N PAGO, TOTAL, CUOTA ASIGNADA y PENDIENTE DE ENTREGA O REINTEGRO), con TOTAL/CUOTA/SALDO como fórmulas.
 */
final class AguaLibro
{
    private const MESES = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];

    private const ORDINALES = ['PRIMER', 'SEGUNDO', 'TERCER', 'CUARTO', 'QUINTO', 'SEXTO'];

    /**
     * @param  Collection<int, array{estacion: Estacion, registro: AguaRegistro|null}>  $tablero
     */
    public function mes(int $anio, int $mes, Collection $tablero): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(self::MESES[$mes]);

        $maxPagos = max(4, (int) $tablero->map(fn ($f) => $f['registro']?->compras->count() ?? 0)->max());
        $primeraPago = 9; // columna I
        $cTotal = $primeraPago + $maxPagos;
        $letra = fn (int $n): string => Coordinate::stringFromColumnIndex($n);

        $hoja->setCellValue('A1', 'REGISTRO DE AGUA — '.mb_strtoupper(self::MESES[$mes]).' '.$anio);
        $hoja->mergeCells('A1:'.$letra($cTotal + 3).'1');
        $hoja->getStyle('A1')->getFont()->setBold(true)->setSize(13);

        $cabeceras = ['ÁREA / ESTACIÓN', 'FRECUENCIA DE COMPRA', 'CONSUMO MENSUAL NETO', 'COSTO UNITARIO (Líquido)', 'GARRAFONES PROPIOS', 'GARRAFONES A COMODATO', 'REFERENCIA', 'DÍA DE ENTREGA'];

        for ($i = 0; $i < $maxPagos; $i++) {
            $cabeceras[] = (self::ORDINALES[$i] ?? ($i + 1).'º').' PAGO';
        }

        array_push($cabeceras, 'TOTAL', 'CUOTA ASIGNADA', 'PENDIENTE DE ENTREGA O REINTEGRO', 'ESTADO');

        foreach ($cabeceras as $i => $texto) {
            $hoja->setCellValue($letra($i + 1).'3', $texto);
        }

        $ultima = $letra(count($cabeceras));
        $hoja->getStyle('A3:'.$ultima.'3')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
            'alignment' => ['wrapText' => true, 'horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $fila = 4;

        foreach ($tablero as $f) {
            $r = $f['registro'];
            $hoja->setCellValue('A'.$fila, mb_strtoupper((string) $f['estacion']->nombre));

            if ($r !== null) {
                $hoja->setCellValue('B'.$fila, mb_strtoupper($r->frecuencia));
                $hoja->setCellValue('C'.$fila, $r->consumo_mensual);
                $hoja->setCellValue('D'.$fila, (float) $r->costo_unitario);
                $hoja->setCellValue('E'.$fila, $r->garrafones_propios);
                $hoja->setCellValue('F'.$fila, $r->garrafones_comodato);
                $hoja->setCellValueExplicit('G'.$fila, (string) $r->referencia, DataType::TYPE_STRING);
                $hoja->setCellValueExplicit('H'.$fila, (string) $r->dia_entrega, DataType::TYPE_STRING);

                foreach ($r->compras->values() as $i => $compra) {
                    $hoja->setCellValue($letra($primeraPago + $i).$fila, (float) $compra->monto);
                }

                $hoja->setCellValue($letra($cTotal + 3).$fila, $r->etiquetaEstado());
            }

            $hoja->setCellValue($letra($cTotal).$fila, '=SUM('.$letra($primeraPago).$fila.':'.$letra($cTotal - 1).$fila.')');
            $hoja->setCellValue($letra($cTotal + 1).$fila, '=C'.$fila.'*D'.$fila);
            $hoja->setCellValue($letra($cTotal + 2).$fila, '='.$letra($cTotal).$fila.'-'.$letra($cTotal + 1).$fila);
            $fila++;
        }

        $hoja->setCellValue('A'.$fila, 'TOTAL');

        foreach ([3 => 'C', 5 => 'E', 6 => 'F'] as $col) {
            $hoja->setCellValue($col.$fila, '=SUM('.$col.'4:'.$col.($fila - 1).')');
        }

        foreach (range($primeraPago, $cTotal + 2) as $n) {
            $hoja->setCellValue($letra($n).$fila, '=SUM('.$letra($n).'4:'.$letra($n).($fila - 1).')');
        }

        $hoja->getStyle('A'.$fila.':'.$ultima.$fila)->getFont()->setBold(true);
        $hoja->getStyle('D4:D'.$fila)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_COMMA_SEPARATED1);
        $hoja->getStyle($letra($primeraPago).'4:'.$letra($cTotal + 2).$fila)->getNumberFormat()->setFormatCode('#,##0.00');
        $hoja->getColumnDimension('A')->setWidth(34);
        $hoja->getColumnDimension('B')->setWidth(14);
        $hoja->getColumnDimension('G')->setWidth(22);
        $hoja->getColumnDimension('H')->setWidth(20);
        $hoja->getRowDimension(3)->setRowHeight(48);
        $hoja->freezePane('B4');

        return $libro;
    }
}
