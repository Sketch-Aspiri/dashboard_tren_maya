<?php

namespace App\Services;

use App\Models\SolicitudInsumo;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Arma los libros de Excel del consolidado mensual a partir de los datos de
 * ConsolidadoInsumosService: el ANEXO "ZO_Cotización" (bloque por estación,
 * con fórmulas), el LISTADO POR PRODUCTO y el CONTROL_FILTRO. Todo texto
 * capturado por usuarios se escribe como cadena explícita, nunca como
 * fórmula.
 */
final class ConsolidadoInsumosLibro
{
    private const DINERO = '"$"#,##0.00';

    private const GRIS = 'D0D0D0';

    /** @var array<int, array<string, mixed>> Notas de cotización por id de partida (PRECIOS_REALES). */
    private array $anotaciones = [];

    private bool $conCotizacion = false;

    /**
     * @param  array<string, mixed>  $datos  Salida de ConsolidadoInsumosService::datos().
     * @param  array<int, array<string, mixed>>|null  $anotaciones  Distinto de null agrega las columnas M a P de cotización (precio estimado, cotización, estatus y observación).
     */
    public function anexo(array $datos, ?array $anotaciones = null): Spreadsheet
    {
        $this->conCotizacion = $anotaciones !== null;
        $this->anotaciones = $anotaciones ?? [];
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('ZO_Cotización');
        $this->anchos($hoja, ['A' => 3.7, 'B' => 5.5, 'C' => 7, 'D' => 10, 'E' => 42, 'F' => 13, 'G' => 14, 'H' => 11, 'I' => 12, 'J' => 40, 'K' => 20, 'L' => 3, 'M' => 14, 'N' => 28, 'O' => 16, 'P' => 46]);
        $this->encabezado($hoja, 'E', 'I', 'J', 'K', $datos);

        $fila = 7;
        $filasTotal = [];

        foreach ($datos['bloques'] as $bloque) {
            $fila = $this->bloque($hoja, $fila, $bloque, $filasTotal);
        }

        $hoja->setCellValue('K3', '=SUM('.implode(',', array_map(fn (int $f) => 'G'.$f, $filasTotal)).')');
        $this->horizontal($hoja);

        return $libro;
    }

    /**
     * @param  array<string, mixed>  $datos  Salida de ConsolidadoInsumosService::datos().
     * @param  list<array<string, mixed>>  $filas  Salida de listadoPorProducto().
     */
    public function listado(array $datos, array $filas): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet()->setTitle('Hoja1');
        $this->anchos($hoja, ['A' => 6, 'B' => 48, 'C' => 11, 'D' => 11, 'E' => 34, 'F' => 14, 'G' => 15, 'H' => 48, 'I' => 24, 'J' => 34]);
        $this->encabezado($hoja, 'B', 'G', 'I', 'J', $datos);

        $cabeceras = ['N.º', 'Insumo homologado', 'Cantidad total', 'Unidad', 'Estación solicitante', 'Precio unitario', 'Importe total', 'Observaciones / justificación', 'Proveedor', 'Observaciones de revisión'];
        $this->cabecera($hoja, 8, $cabeceras, 'A');

        $fila = 9;
        foreach ($filas as $i => $item) {
            $hoja->setCellValue('A'.$fila, $i + 1);
            $this->texto($hoja, 'B'.$fila, $item['descripcion']);
            $hoja->setCellValue('C'.$fila, $item['cantidad']);
            $this->texto($hoja, 'D'.$fila, $item['unidad']);
            $this->texto($hoja, 'E'.$fila, $item['estaciones']);
            $hoja->setCellValue('F'.$fila, $item['precio']);
            $hoja->setCellValue('G'.$fila, $item['importe']);
            $this->texto($hoja, 'H'.$fila, $item['observaciones']);
            $this->texto($hoja, 'I'.$fila, $item['proveedor']);
            $this->texto($hoja, 'J'.$fila, $item['revision']);
            $fila++;
        }

        $hoja->setCellValue('F'.$fila, 'TOTAL');
        $hoja->setCellValue('G'.$fila, $filas === [] ? 0 : '=SUM(G9:G'.($fila - 1).')');
        $hoja->getStyle('F'.$fila.':G'.$fila)->getFont()->setBold(true);
        $hoja->getStyle('F9:G'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);
        $this->bordes($hoja, 'A8:J'.$fila);
        $hoja->getStyle('B9:J'.$fila)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $hoja->setCellValue('J3', '=G'.$fila);
        $this->horizontal($hoja);

        return $libro;
    }

    /**
     * @param  array<string, mixed>  $datos  Salida de ConsolidadoInsumosService::datos().
     * @param  list<array<string, mixed>>  $alertas  Salida de alertas().
     */
    public function control(array $datos, array $alertas): Spreadsheet
    {
        $libro = new Spreadsheet;
        $resumen = $libro->getActiveSheet()->setTitle('Resumen');
        $this->anchos($resumen, ['A' => 40, 'B' => 18]);
        $this->cabecera($resumen, 1, ['Concepto', 'Valor'], 'A');

        $bloques = collect($datos['bloques']);
        $conceptos = [
            ['Mes', $datos['mesNombre'].' '.$datos['anio']],
            ['Solicitudes aprobadas (incluidas)', $bloques->where('incluida', true)->count()],
            ['Solicitudes enviadas u observadas (retenidas)', $bloques->filter(fn ($b) => $b['solicitud'] && ! $b['incluida'] && $b['solicitud']->estado !== 'borrador')->count()],
            ['Solicitudes en borrador (retenidas)', $bloques->filter(fn ($b) => $b['solicitud']?->estado === 'borrador')->count()],
            ['Estaciones sin solicitud', $bloques->filter(fn ($b) => $b['solicitud'] === null)->count()],
            ['Partidas incluidas', $bloques->sum(fn ($b) => $b['partidas']->count())],
            ['Subtotal', $datos['subtotal']],
            ['IVA (por proveedor)', $datos['iva']],
            ['Total con IVA', $datos['total']],
            ['Fondo rotatorio', $datos['tope']],
            ['Pendiente por ejercer', $datos['pendiente']],
            ['Alertas', count($alertas)],
        ];
        foreach ($conceptos as $i => [$concepto, $valor]) {
            $this->texto($resumen, 'A'.($i + 2), $concepto);
            is_string($valor) ? $this->texto($resumen, 'B'.($i + 2), $valor) : $resumen->setCellValue('B'.($i + 2), $valor);
        }
        $resumen->getStyle('B8:B12')->getNumberFormat()->setFormatCode(self::DINERO);

        $hoja = $libro->createSheet()->setTitle('Alertas');
        $this->anchos($hoja, ['A' => 24, 'B' => 52, 'C' => 9, 'D' => 12, 'E' => 12, 'F' => 46, 'G' => 10]);
        $this->cabecera($hoja, 1, ['Estación', 'Descripción', 'Cant.', 'P.U.', 'Estado', 'Alertas', 'En anexo'], 'A');

        foreach ($alertas as $i => $alerta) {
            $fila = $i + 2;
            $this->texto($hoja, 'A'.$fila, $alerta['estacion']);
            $this->texto($hoja, 'B'.$fila, $alerta['descripcion']);
            $hoja->setCellValue('C'.$fila, $alerta['cantidad']);
            $hoja->setCellValue('D'.$fila, $alerta['precio']);
            $this->texto($hoja, 'E'.$fila, $alerta['estado']);
            $this->texto($hoja, 'F'.$fila, $alerta['alertas']);
            $this->texto($hoja, 'G'.$fila, $alerta['enAnexo'] ? 'Sí' : 'No');
        }
        $hoja->getStyle('D2:D'.max(2, count($alertas) + 1))->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('A2:G'.max(2, count($alertas) + 1))->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $libro->setActiveSheetIndex(0);

        return $libro;
    }

    /**
     * Rótulos de arriba (Coordinación, Dirección, título, mes) y el cuadro del
     * fondo rotatorio.
     *
     * @param  array<string, mixed>  $datos
     */
    private function encabezado(Worksheet $hoja, string $desde, string $hasta, string $etiqueta, string $valor, array $datos): void
    {
        $rotulos = [
            2 => 'COORDINACIÓN GENERAL DE GESTIÓN DE INFRAESTRUCTURA FERROVIARIA',
            3 => 'DIRECCIÓN DE GESTIÓN TERRITORIAL ZONA ORIENTE',
            4 => 'SOLICITUD DE INSUMOS PARA MANTENIMIENTO A ESTACIONES',
            5 => 'INSUMOS REQUERIDOS DEL MES: '.mb_strtoupper($datos['mesNombre']),
        ];
        foreach ($rotulos as $fila => $texto) {
            $hoja->mergeCells($desde.$fila.':'.$hasta.$fila);
            $this->texto($hoja, $desde.$fila, $texto);
            $hoja->getStyle($desde.$fila)->getFont()->setBold(true);
        }

        $this->texto($hoja, $etiqueta.'2', 'TOTAL FONDO ROTATORIO');
        $hoja->setCellValue($valor.'2', $datos['tope']);
        $this->texto($hoja, $etiqueta.'3', 'TOTAL ');
        $hoja->setCellValue($valor.'3', $datos['total']);
        $this->texto($hoja, $etiqueta.'4', 'TOTAL PEND. POR EJERCER');
        $hoja->setCellValue($valor.'4', '='.$valor.'2-'.$valor.'3');
        $hoja->getStyle($etiqueta.'2:'.$valor.'4')->getFont()->setBold(true);
        $hoja->getStyle($valor.'2:'.$valor.'4')->getNumberFormat()->setFormatCode(self::DINERO);
    }

    /**
     * Un bloque de estación del ANEXO. Devuelve la siguiente fila libre y
     * agrega a $filasTotal la fila de su TOTAL.
     *
     * @param  array<string, mixed>  $bloque
     * @param  list<int>  $filasTotal
     */
    private function bloque(Worksheet $hoja, int $fila, array $bloque, array &$filasTotal): int
    {
        $estacion = $bloque['estacion'];
        $solicitud = $bloque['solicitud'];

        foreach ([$fila, $fila + 1] as $f) {
            $hoja->mergeCells('B'.$f.':K'.$f);
            $hoja->getStyle('B'.$f.':K'.$f)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(self::GRIS);
            $hoja->getStyle('B'.$f)->getFont()->setBold(true);
        }
        $this->texto($hoja, 'B'.$fila, ($estacion->codigo ?? '').'_'.mb_strtoupper($estacion->nombre));
        $this->texto($hoja, 'B'.($fila + 1), trim(($solicitud?->solicitante_nombre ?? '').($solicitud?->solicitante_puesto ? ', '.$solicitud->solicitante_puesto : ''), ', '));

        $cabecera = $fila + 2;
        $this->cabecera($hoja, $cabecera, ['N°', 'Cant', 'Unidad', 'Descripción', 'P.U.', 'Precio total', 'P.Ptal', 'P.Ptal/Fin', 'Observaciones/Justificación de insumos', 'Proveedor'], 'B');

        if ($this->conCotizacion) {
            $this->cabecera($hoja, $cabecera, ['P.U. estimado', 'Cotización', 'Estatus fase A', 'Observación'], 'M');
        }

        $primera = $cabecera + 1;
        $fila = $primera;
        $filasPorGrupo = [];

        if ($bloque['partidas']->isEmpty()) {
            $hoja->setCellValue('B'.$fila, 1);
            $this->texto($hoja, 'E'.$fila, $this->motivoSinPartidas($solicitud));
            $hoja->setCellValue('G'.$fila, '=C'.$fila.'*F'.$fila);
            $fila++;
        }

        foreach ($bloque['partidas'] as $partida) {
            $hoja->setCellValue('B'.$fila, $partida->numero);
            $hoja->setCellValue('C'.$fila, (float) $partida->cantidad);
            $this->texto($hoja, 'D'.$fila, $partida->unidad);
            $this->texto($hoja, 'E'.$fila, $partida->descripcion);
            $hoja->setCellValue('F'.$fila, $partida->precio_unitario === null ? null : (float) $partida->precio_unitario);
            $hoja->setCellValue('G'.$fila, '=C'.$fila.'*F'.$fila);
            $this->texto($hoja, 'H'.$fila, $partida->partida_presupuestal);
            $this->texto($hoja, 'I'.$fila, $partida->partida_final ?? $partida->partida_presupuestal);
            $this->texto($hoja, 'J'.$fila, $partida->justificacion);
            $this->texto($hoja, 'K'.$fila, $partida->proveedor);
            $filasPorGrupo[ProveedorService::clave($partida->proveedor)][] = $fila;
            $this->notaDeCotizacion($hoja, $fila, $partida->id);
            $fila++;
        }

        $ultima = $fila - 1;
        $hoja->getStyle('F'.$primera.':G'.$ultima)->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('B'.$primera.':K'.$ultima)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        $hoja->setCellValue('E'.$fila, 'Subtotal');
        $hoja->setCellValue('G'.$fila, '=SUM(G'.$primera.':G'.$ultima.')');

        // Una fila de IVA por proveedor, con su tasa (8 % o 16 %).
        $subtotal = $fila;
        $ivas = $bloque['desglose'] !== [] ? $bloque['desglose'] : [['clave' => '', 'proveedor' => null, 'tasa' => (int) config('insumos.iva_defecto')]];

        foreach ($ivas as $grupo) {
            $fila++;
            $celdas = array_map(fn (int $f) => 'G'.$f, $filasPorGrupo[$grupo['clave']] ?? []);
            $this->texto($hoja, 'E'.$fila, 'IVA '.$grupo['tasa'].' %'.($grupo['proveedor'] !== null ? ' — '.$grupo['proveedor'] : ''));
            $hoja->setCellValue('G'.$fila, '='.$grupo['tasa'].'%*'.($celdas === [] ? 'G'.$subtotal : '('.implode('+', $celdas).')'));
        }

        $filaTotal = $fila + 1;
        $hoja->setCellValue('E'.$filaTotal, 'TOTAL ');
        $hoja->setCellValue('G'.$filaTotal, '=SUM(G'.$subtotal.':G'.$fila.')');
        $hoja->getStyle('G'.$subtotal.':G'.$filaTotal)->getNumberFormat()->setFormatCode(self::DINERO);
        $hoja->getStyle('E'.$filaTotal.':G'.$filaTotal)->getFont()->setBold(true);
        $this->bordes($hoja, 'B'.$cabecera.':K'.$filaTotal);

        $filasTotal[] = $filaTotal;

        return $filaTotal + 2;
    }

    /**
     * Columnas M a P del PRECIOS_REALES: precio estimado, cotización que lo
     * resolvió, estatus de la fase A y observación.
     */
    private function notaDeCotizacion(Worksheet $hoja, int $fila, int $partidaId): void
    {
        $nota = $this->anotaciones[$partidaId] ?? null;

        if ($nota === null) {
            return;
        }

        $hoja->setCellValue('M'.$fila, $nota['estimado']);
        $hoja->getStyle('M'.$fila)->getNumberFormat()->setFormatCode(self::DINERO);
        $this->texto($hoja, 'N'.$fila, $nota['cotizacion']);
        $this->texto($hoja, 'O'.$fila, $nota['estatus']);
        $this->texto($hoja, 'P'.$fila, $nota['observacion']);
        $hoja->getStyle('O'.$fila)->getFont()->getColor()->setRGB($nota['estatus'] === 'ACTUALIZADO' ? '1B7F3B' : 'C0392B');
        $hoja->getStyle('N'.$fila.':P'.$fila)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    private function motivoSinPartidas(?SolicitudInsumo $solicitud): string
    {
        return match ($solicitud?->estado) {
            null, 'borrador' => 'No realizaron solicitud a la fecha',
            default => 'Solicitud '.$solicitud->estado.', pendiente de aprobación de la Zona',
        };
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

    private function bordes(Worksheet $hoja, string $rango): void
    {
        $hoja->getStyle($rango)->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
    }

    private function horizontal(Worksheet $hoja): void
    {
        $hoja->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
        $hoja->getSheetView()->setZoomScale(90);
    }
}
