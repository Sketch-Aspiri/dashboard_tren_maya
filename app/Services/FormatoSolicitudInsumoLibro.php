<?php

namespace App\Services;

use App\Models\SolicitudInsumo;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Exporta una solicitud en el formato oficial "FORMATO DE SOLICITUD DE
 * INSUMOS PARA MANTENIMIENTO" (para firma y archivo). Parte de la plantilla
 * resources/documentos/formato_solicitud_insumos.xlsx para conservar logos y
 * estilos; corrige sus fórmulas (IVA 8 %, precio × cantidad) y agrega filas
 * cuando hay más de las 12 que trae. Todo texto capturado se escribe como
 * cadena explícita, nunca como fórmula.
 */
final class FormatoSolicitudInsumoLibro
{
    public function __construct(private readonly ProveedorService $proveedores) {}

    private const PRIMERA_FILA = 11;

    private const FILAS_PLANTILLA = 12;

    private const DINERO = '"$"#,##0.00';

    /** Caracteres por renglón de las columnas Descripción (E) y Observaciones (I). */
    private const CARACTERES_DESCRIPCION = 24;

    private const CARACTERES_JUSTIFICACION = 24;

    /**
     * @param  bool  $conPrecios  false deja en blanco precios y totales.
     */
    public function generar(SolicitudInsumo $solicitud, bool $conPrecios = true): Spreadsheet
    {
        $libro = IOFactory::load(resource_path('documentos/formato_solicitud_insumos.xlsx'));
        $libro->removeSheetByIndex($libro->getIndex($libro->getSheetByName('Proveedores')));
        $libro->setActiveSheetIndex(0);
        $hoja = $libro->getSheetByName('SOLICITUD');

        $partidas = $solicitud->partidas;
        $extra = max(0, $partidas->count() - self::FILAS_PLANTILLA);
        $ultima = self::PRIMERA_FILA + max($partidas->count(), self::FILAS_PLANTILLA) - 1;

        $this->cabecera($hoja, $solicitud);
        $this->prepararFilas($hoja, $extra);
        $filasPorTasa = $this->partidas($hoja, $solicitud, $conPrecios);
        $this->totales($hoja, $ultima, $conPrecios, $filasPorTasa);
        $this->firmas($hoja, $solicitud, $extra);
        $this->impresion($hoja, 33 + $extra, $extra === 0);

        return $libro;
    }

    private function cabecera(Worksheet $hoja, SolicitudInsumo $solicitud): void
    {
        $estacion = $solicitud->estacion->nombre;
        $lugar = trim((string) $solicitud->lugar_trabajo);

        $this->texto($hoja, 'D6', $lugar !== '' && mb_strtolower($lugar) !== mb_strtolower($estacion) ? $estacion.' — '.$lugar : $estacion);
        $this->texto($hoja, 'I5', mb_strtoupper(ConsolidadoInsumosService::MESES[$solicitud->mes]).' '.$solicitud->anio);
        $hoja->setCellValue('I6', null);
        $hoja->setCellValue('I7', null);
        $this->texto($hoja, $solicitud->tipo_servicio === '2do escalón' ? 'I7' : 'I6', 'X');
    }

    /**
     * Vacía las 12 filas de ejemplo y, si hacen falta más, inserta filas
     * nuevas antes del subtotal con el mismo estilo.
     */
    private function prepararFilas(Worksheet $hoja, int $extra): void
    {
        $ultimaPlantilla = self::PRIMERA_FILA + self::FILAS_PLANTILLA - 1;

        if ($extra > 0) {
            $hoja->insertNewRowBefore($ultimaPlantilla + 1, $extra);

            for ($fila = $ultimaPlantilla + 1; $fila <= $ultimaPlantilla + $extra; $fila++) {
                $hoja->duplicateStyle($hoja->getStyle('B'.$ultimaPlantilla.':I'.$ultimaPlantilla), 'B'.$fila.':I'.$fila);
            }
        }

        for ($fila = self::PRIMERA_FILA; $fila <= $ultimaPlantilla + $extra; $fila++) {
            foreach (['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I'] as $columna) {
                $hoja->setCellValue($columna.$fila, null);
            }
        }
    }

    /**
     * @return array<int, list<int>> Filas de partida con precio, por tasa de IVA (%).
     */
    private function partidas(Worksheet $hoja, SolicitudInsumo $solicitud, bool $conPrecios): array
    {
        $tasas = $this->proveedores->tasas();
        $filasPorTasa = [];

        foreach ($solicitud->partidas as $indice => $partida) {
            $fila = self::PRIMERA_FILA + $indice;

            $hoja->setCellValue('B'.$fila, $partida->numero);
            $hoja->setCellValue('C'.$fila, (float) $partida->cantidad);
            $this->texto($hoja, 'D'.$fila, $partida->unidad);
            $this->texto($hoja, 'E'.$fila, $partida->descripcion);
            $this->texto($hoja, 'F'.$fila, $conPrecios ? $partida->codigo : null);
            $this->texto($hoja, 'I'.$fila, $partida->justificacion);

            if ($conPrecios && $partida->precio_unitario !== null) {
                $hoja->setCellValue('G'.$fila, (float) $partida->precio_unitario);
                $hoja->setCellValue('H'.$fila, '=C'.$fila.'*G'.$fila);
                $filasPorTasa[$this->proveedores->tasaAplicable($partida->proveedor, $tasas)][] = $fila;
            }

            $this->formatoFila($hoja, $fila, $partida->descripcion, $partida->justificacion);
        }

        return $filasPorTasa;
    }

    /**
     * Alineación igual en todas las filas (también las insertadas) y altura
     * según el texto, porque Excel no ajusta solo la altura de celdas combinadas.
     */
    private function formatoFila(Worksheet $hoja, int $fila, string $descripcion, ?string $justificacion): void
    {
        $alinear = fn (string $rango, string $horizontal) => $hoja->getStyle($rango)->getAlignment()
            ->setHorizontal($horizontal)->setVertical(Alignment::VERTICAL_TOP)->setWrapText(true);

        $alinear('B'.$fila.':D'.$fila, Alignment::HORIZONTAL_CENTER);
        $alinear('E'.$fila, Alignment::HORIZONTAL_LEFT);
        $alinear('F'.$fila, Alignment::HORIZONTAL_CENTER);
        $alinear('G'.$fila.':H'.$fila, Alignment::HORIZONTAL_RIGHT);
        $alinear('I'.$fila, Alignment::HORIZONTAL_LEFT);
        $hoja->getStyle('G'.$fila.':H'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);

        $lineas = max(ceil(mb_strlen($descripcion) / self::CARACTERES_DESCRIPCION), ceil(mb_strlen((string) $justificacion) / self::CARACTERES_JUSTIFICACION), 1);
        $hoja->getRowDimension($fila)->setRowHeight(max(20, $lineas * 15));
    }

    /**
     * La plantilla calcula el IVA al 16 % y el precio unitario como F×B;
     * aquí quedan como en el ANEXO: subtotal, IVA y total. El IVA usa la
     * tasa de cada proveedor (8 % o 16 %), sumando las filas de cada tasa.
     *
     * @param  array<int, list<int>>  $filasPorTasa
     */
    private function totales(Worksheet $hoja, int $ultima, bool $conPrecios, array $filasPorTasa): void
    {
        $subtotal = $ultima + 1;

        $hoja->setCellValue('H'.$subtotal, $conPrecios ? '=SUM(H'.self::PRIMERA_FILA.':H'.$ultima.')' : null);
        $hoja->setCellValue('H'.($subtotal + 1), $conPrecios ? $this->formulaIva($filasPorTasa, $subtotal) : null);
        $hoja->setCellValue('H'.($subtotal + 2), $conPrecios ? '=H'.$subtotal.'+H'.($subtotal + 1) : null);
        $hoja->getStyle('H'.$subtotal.':H'.($subtotal + 2))->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * Con una sola tasa: =8%*subtotal. Con varias: =8%*(H11+H13)+16%*(H12).
     *
     * @param  array<int, list<int>>  $filasPorTasa
     */
    private function formulaIva(array $filasPorTasa, int $subtotal): string
    {
        if (count($filasPorTasa) <= 1) {
            return '='.(array_key_first($filasPorTasa) ?? (int) config('insumos.iva_defecto')).'%*H'.$subtotal;
        }

        ksort($filasPorTasa);

        return '='.implode('+', array_map(
            fn (int $tasa, array $filas) => $tasa.'%*('.implode('+', array_map(fn (int $f) => 'H'.$f, $filas)).')',
            array_keys($filasPorTasa),
            $filasPorTasa,
        ));
    }

    private function firmas(Worksheet $hoja, SolicitudInsumo $solicitud, int $extra): void
    {
        $puesto = 28 + $extra;
        $nombre = 32 + $extra;

        $hoja->unmergeCells('B'.$nombre.':C'.$nombre);
        $hoja->mergeCells('B'.$puesto.':D'.$puesto);
        $hoja->mergeCells('B'.$nombre.':D'.$nombre);
        $this->texto($hoja, 'B'.$puesto, $solicitud->solicitante_puesto);
        $this->texto($hoja, 'B'.$nombre, $solicitud->solicitante_nombre);
        $hoja->getStyle('B'.$puesto)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $hoja->getStyle('B'.$nombre)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setWrapText(true);
        $hoja->getRowDimension($nombre)->setRowHeight(32);
    }

    /**
     * Hasta 12 partidas el formato cabe en una sola hoja, como el oficial;
     * con más se reparte en varias a lo largo.
     */
    private function impresion(Worksheet $hoja, int $ultimaFila, bool $unaHoja): void
    {
        $hoja->getPageSetup()
            ->setPrintArea('B2:I'.$ultimaFila)
            ->setOrientation(PageSetup::ORIENTATION_PORTRAIT)
            ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight($unaHoja ? 1 : 0);
    }

    private function texto(Worksheet $hoja, string $celda, mixed $valor): void
    {
        if ($valor === null || $valor === '') {
            return;
        }

        $hoja->setCellValueExplicit($celda, (string) $valor, DataType::TYPE_STRING);
    }
}
