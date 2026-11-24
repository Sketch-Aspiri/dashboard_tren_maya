<?php

namespace App\Services\Afluencia;

use App\Models\Estacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Excel anual del flujo de pasajeros con la misma disposición del reporte original: una hoja por mes y dos bloques
 * de estaciones (DÍA / ABORDAN / BOLETOS VENDIDOS), con TOTAL como fórmula. Los días sin captura quedan vacíos.
 */
final class AfluenciaLibro
{
    private const MESES = [1 => 'ENERO', 2 => 'FEBRERO', 3 => 'MARZO', 4 => 'ABRIL', 5 => 'MAYO', 6 => 'JUNIO', 7 => 'JULIO', 8 => 'AGOSTO', 9 => 'SEPTIEMBRE', 10 => 'OCTUBRE', 11 => 'NOVIEMBRE', 12 => 'DICIEMBRE'];

    /** Códigos de estación por bloque, en el orden del reporte original. */
    private const BLOQUES = [['23', '24', '25', '26', '27'], ['28', '29', '30', '31']];

    /**
     * @param  Collection<int, Estacion>  $estaciones
     * @param  array<int, array<string, array{abordan: int|null, boletos: int|null}>>  $datos
     */
    public function anio(int $anio, Collection $estaciones, array $datos): Spreadsheet
    {
        $libro = new Spreadsheet;
        $porCodigo = $estaciones->keyBy('codigo');

        foreach (self::MESES as $mes => $titulo) {
            $hoja = $mes === 1 ? $libro->getActiveSheet() : $libro->createSheet();
            $hoja->setTitle($titulo);
            $hoja->setCellValue('H2', 'Coordinación General de Gestión de Infraestructura Ferroviaria');
            $hoja->setCellValue('H3', 'Dirección de Gestión Territorial Zona Oriente');
            $hoja->setCellValue('H4', 'Reporte diario de abordajes y boletos vendidos');
            $hoja->setCellValue('L5', $titulo.' '.$anio);
            $hoja->getStyle('H2:L5')->getFont()->setBold(true);

            $dias = CarbonImmutable::create($anio, $mes, 1)->daysInMonth;
            $fila = 7;

            foreach (self::BLOQUES as $codigos) {
                foreach ($codigos as $i => $codigo) {
                    $estacion = $porCodigo->get($codigo);

                    if ($estacion !== null) {
                        $this->bloque($hoja, 2 + 4 * $i, $fila, $estacion, $datos[$estacion->id] ?? [], $anio, $mes, $dias);
                    }
                }

                $fila += $dias + 5;
            }

            foreach (range('A', 'U') as $col) {
                $hoja->getColumnDimension($col)->setWidth(in_array($col, ['E', 'I', 'M', 'Q'], true) ? 3 : 12);
            }
        }

        return $libro;
    }

    /**
     * @param  array<string, array{abordan: int|null, boletos: int|null}>  $datos
     */
    private function bloque($hoja, int $col, int $fila, Estacion $estacion, array $datos, int $anio, int $mes, int $dias): void
    {
        $c = fn (int $n): string => Coordinate::stringFromColumnIndex($n);
        $hoja->setCellValueExplicit($c($col).$fila, mb_strtoupper((string) $estacion->nombre), DataType::TYPE_STRING);
        $hoja->mergeCells($c($col).$fila.':'.$c($col + 2).$fila);

        foreach (['DÍA', 'ABORDAN', 'BOLETOS VENDIDOS'] as $k => $texto) {
            $hoja->setCellValue($c($col + $k).($fila + 1), $texto);
        }

        $hoja->getStyle($c($col).$fila.':'.$c($col + 2).($fila + 1))->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D9E1F2']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'wrapText' => true],
        ]);

        $primera = $fila + 2;

        for ($d = 1; $d <= $dias; $d++) {
            $r = $primera + $d - 1;
            $v = $datos[CarbonImmutable::create($anio, $mes, $d)->toDateString()] ?? null;
            $hoja->setCellValue($c($col).$r, $d);

            if ($v !== null && $v['abordan'] !== null) {
                $hoja->setCellValue($c($col + 1).$r, $v['abordan']);
            }

            if ($v !== null && $v['boletos'] !== null) {
                $hoja->setCellValue($c($col + 2).$r, $v['boletos']);
            }
        }

        $total = $primera + $dias;
        $hoja->setCellValue($c($col).$total, 'TOTAL:');
        $hoja->setCellValue($c($col + 1).$total, '=SUM('.$c($col + 1).$primera.':'.$c($col + 1).($total - 1).')');
        $hoja->setCellValue($c($col + 2).$total, '=SUM('.$c($col + 2).$primera.':'.$c($col + 2).($total - 1).')');
        $hoja->getStyle($c($col).$total.':'.$c($col + 2).$total)->getFont()->setBold(true);
    }
}
