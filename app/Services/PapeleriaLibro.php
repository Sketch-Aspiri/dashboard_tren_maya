<?php

namespace App\Services;

use App\Models\CatalogoPapeleria;
use App\Models\Estacion;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Arma el Excel "SOLICITUD PAPELERIA" con el mismo formato que usa la
 * Coordinación: hoja Resumen (No., Estacion/Edificio, Articulo, Unidad de
 * medida, Cantidad, Comentarios) y hoja oculta CATALOGOS con las listas de
 * las validaciones. Todo texto capturado se escribe como cadena explícita,
 * nunca como fórmula.
 */
final class PapeleriaLibro
{
    private const COLOR_ENCABEZADO = '0C343D';

    private const ULTIMA_FILA_LISTAS = 1000;

    /**
     * @param  Collection<int, array{estacion: string, articulo: string, unidad: string, cantidad: float, comentarios: string|null}>  $filas
     */
    public function resumen(Collection $filas): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Resumen');

        $hoja->mergeCells('A1:F1')->mergeCells('A2:F2');
        $hoja->setCellValueExplicit('A1', config('papeleria.titulo'), DataType::TYPE_STRING);
        $hoja->setCellValueExplicit('A2', config('papeleria.subtitulo'), DataType::TYPE_STRING);
        $hoja->getStyle('A1:A2')->getFont()->setBold(true);
        $hoja->getStyle('A1:A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle('A1')->getFont()->setSize(14);

        foreach (['No.', 'Estacion/Edificio', 'Articulo', 'Unidad de medida', 'Cantidad', 'Comentarios'] as $i => $titulo) {
            $hoja->setCellValueExplicit([$i + 1, 3], $titulo, DataType::TYPE_STRING);
        }
        $hoja->getStyle('A3:F3')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => self::COLOR_ENCABEZADO]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);

        $fila = 4;
        foreach ($filas as $indice => $dato) {
            $hoja->setCellValue('A'.$fila, $indice + 1);
            $hoja->setCellValueExplicit('B'.$fila, $dato['estacion'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('C'.$fila, $dato['articulo'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('D'.$fila, $dato['unidad'], DataType::TYPE_STRING);
            $hoja->setCellValue('E'.$fila, $dato['cantidad']);
            $hoja->setCellValueExplicit('F'.$fila, (string) ($dato['comentarios'] ?? ''), DataType::TYPE_STRING);
            $fila++;
        }

        $ultima = max($fila - 1, 4);
        $hoja->getStyle('A3:F'.$ultima)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $hoja->getStyle('A4:F'.$ultima)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
        $hoja->getStyle('A4:A'.$ultima)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $hoja->getStyle('E4:E'.$ultima)->getNumberFormat()->setFormatCode('#,##0.##');

        foreach (['A' => 6, 'B' => 26, 'C' => 60, 'D' => 18, 'E' => 11, 'F' => 40] as $columna => $ancho) {
            $hoja->getColumnDimension($columna)->setWidth($ancho);
        }
        $hoja->freezePane('A4');

        $this->listas($libro, $hoja);

        return $libro;
    }

    /**
     * Hoja oculta CATALOGOS y listas desplegables de B, C y D (como el formato).
     */
    private function listas(Spreadsheet $libro, Worksheet $resumen): void
    {
        $catalogos = $libro->createSheet()->setTitle('CATALOGOS');
        $estaciones = Estacion::query()->orderBy('orden')->pluck('nombre')->map(fn ($n) => mb_strtoupper($n))->all();
        $articulos = CatalogoPapeleria::query()->where('activo', true)->orderBy('descripcion')->pluck('descripcion')->all();
        $unidades = config('papeleria.unidades');

        foreach (['Estacion/Edificio', 'Articulos', 'unidad'] as $i => $titulo) {
            $catalogos->setCellValueExplicit([$i + 1, 1], $titulo, DataType::TYPE_STRING);
        }
        foreach ([$estaciones, $articulos, $unidades] as $columna => $valores) {
            foreach (array_values($valores) as $i => $valor) {
                $catalogos->setCellValueExplicit([$columna + 1, $i + 2], (string) $valor, DataType::TYPE_STRING);
            }
        }
        $catalogos->setSheetState(Worksheet::SHEETSTATE_HIDDEN);

        $listas = [
            'B' => ['A', count($estaciones)],
            'C' => ['B', count($articulos)],
            'D' => ['C', count($unidades)],
        ];
        foreach ($listas as $columna => [$origen, $total]) {
            $validacion = new DataValidation;
            $validacion->setType(DataValidation::TYPE_LIST)->setAllowBlank(true)->setShowDropDown(false)
                ->setShowErrorMessage(true)
                ->setFormula1('CATALOGOS!$'.$origen.'$2:$'.$origen.'$'.max(2, $total + 1));
            $resumen->setDataValidation($columna.'4:'.$columna.self::ULTIMA_FILA_LISTAS, $validacion);
        }
    }
}
