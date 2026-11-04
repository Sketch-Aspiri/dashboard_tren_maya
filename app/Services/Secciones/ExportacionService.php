<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\Hoja\FormulasHoja;
use App\Support\EstiloCelda;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Una tabla de una sección -> .xlsx (encabezados + una fila por registro),
 * con el formato de la hoja de cálculo: estilos de celda, anchos, celdas
 * combinadas, formato de tabla y fórmulas reales.
 *
 * Seguridad (inyección de fórmulas): el texto siempre se escribe como
 * texto; solo una celda que en la hoja ya es fórmula se exporta como
 * fórmula, y solo si FormulasHoja::aExcel() la acepta (lista blanca de
 * funciones, sin HYPERLINK/WEBSERVICE/DDE). Con búsqueda u orden aplicados
 * las posiciones ya no corresponden a la hoja: las fórmulas salen como
 * texto y no se exportan combinaciones ni formato de tabla.
 */
final class ExportacionService
{
    private const CHUNK = 1000;

    /** Píxeles por unidad de ancho de columna de Excel (aprox.). */
    private const PX_POR_ANCHO = 7;

    public function __construct(
        private readonly ConsultaSeccionService $consulta,
        private readonly NormalizadorValores $normalizador,
    ) {}

    /**
     * @param  array{q: ?string, sort: ?string, direction: string}  $filtros
     */
    public function descargar(SeccionTabla $tabla, array $filtros, ?User $usuario = null): StreamedResponse
    {
        $libro = $this->libro($tabla, $filtros, $usuario);
        // Con varias tablas el nombre del archivo dice cuál se exportó.
        $partes = $tabla->seccion->tablas()->count() > 1 ? [$tabla->seccion->nombre, $tabla->nombre] : [$tabla->seccion->nombre];
        $nombre = Str::slug(implode(' ', $partes)).'-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($libro): void {
            $escritor = new Xlsx($libro);
            // Excel recalcula al abrir; PhpSpreadsheet no necesita evaluarlas.
            $escritor->setPreCalculateFormulas(false);
            $escritor->save('php://output');
            $libro->disconnectWorksheets();
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @param  array{q: ?string, sort: ?string, direction: string, estacion?: ?string}  $filtros
     */
    public function libro(SeccionTabla $tabla, array $filtros, ?User $usuario = null): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(Str::limit(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $tabla->nombre) ?? 'Tabla', 28, ''));

        $columnas = $tabla->columnas->values();
        $comoHoja = ($filtros['q'] ?? null) === null && ($filtros['sort'] ?? null) === null && ($filtros['estacion'] ?? null) === null;

        foreach ($columnas as $indice => $columna) {
            // Texto explícito: un encabezado "=..." venido de un documento no
            // debe convertirse en fórmula al abrir el archivo.
            $hoja->setCellValueExplicit([$indice + 1, 1], $columna->nombre, DataType::TYPE_STRING);
        }

        $hoja->getStyle([1, 1, max(1, $columnas->count()), 1])->getFont()->setBold(true);
        $renglon = 2;

        $this->consulta->consultaFilas($tabla, $filtros, $usuario)
            ->chunk(self::CHUNK, function ($filas) use ($hoja, $columnas, $comoHoja, &$renglon): void {
                /** @var SeccionFila $fila */
                foreach ($filas as $fila) {
                    foreach ($columnas as $indice => $columna) {
                        $this->escribirCelda($hoja, $indice + 1, $renglon, $fila, $columna, $comoHoja);
                    }

                    $renglon++;
                }
            });

        if ($comoHoja) {
            $this->aplicarDiseno($hoja, $tabla, $renglon - 2);
        }

        $this->aplicarAnchos($hoja, $columnas);

        return $libro;
    }

    private function escribirCelda(Worksheet $hoja, int $columna, int $renglon, SeccionFila $fila, SeccionColumna $definicion, bool $comoHoja): void
    {
        $crudo = $fila->datos[$definicion->clave] ?? null;
        $formula = $comoHoja && FormulasHoja::esFormula($crudo) ? FormulasHoja::aExcel($crudo) : null;

        match (true) {
            $formula !== null => $hoja->setCellValueExplicit([$columna, $renglon], $formula, DataType::TYPE_FORMULA),
            // Los números van como número (para que SUMA y compañía funcionen en Excel); no hay riesgo de inyección.
            $definicion->tipo === TipoColumna::Numero && (is_int($crudo) || is_float($crudo)) => $hoja->setCellValueExplicit([$columna, $renglon], $crudo, DataType::TYPE_NUMERIC),
            default => $hoja->setCellValueExplicit([$columna, $renglon], $this->valor($fila, $definicion), DataType::TYPE_STRING),
        };

        $estilo = $fila->formato[$definicion->clave] ?? null;

        if (is_array($estilo)) {
            $this->aplicarEstilo($hoja, $columna, $renglon, EstiloCelda::limpiar($estilo) ?? []);
        }
    }

    /**
     * @param  array<string, bool|int|string>  $estilo
     */
    private function aplicarEstilo(Worksheet $hoja, int $columna, int $renglon, array $estilo): void
    {
        if ($estilo === []) {
            return;
        }

        $celda = $hoja->getStyle([$columna, $renglon]);
        $fuente = $celda->getFont();
        $fuente->setBold($estilo['n'] ?? false)->setItalic($estilo['k'] ?? false)->setStrikethrough($estilo['t'] ?? false);

        if ($estilo['s'] ?? false) {
            $fuente->setUnderline(Font::UNDERLINE_SINGLE);
        }

        if (isset($estilo['color'])) {
            $fuente->getColor()->setRGB(ltrim((string) $estilo['color'], '#'));
        }

        if (isset($estilo['tam'])) {
            $fuente->setSize((int) $estilo['tam']);
        }

        if (isset($estilo['fondo'])) {
            $celda->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim((string) $estilo['fondo'], '#'));
        }

        if (isset($estilo['alin'])) {
            $celda->getAlignment()->setHorizontal(match ($estilo['alin']) {
                'centro' => Alignment::HORIZONTAL_CENTER,
                'der' => Alignment::HORIZONTAL_RIGHT,
                default => Alignment::HORIZONTAL_LEFT,
            });
        }

        if ($estilo['a'] ?? false) {
            $celda->getAlignment()->setWrapText(true);
        }
    }

    /**
     * Combinaciones y formato de tabla. En el archivo la fila 1 son los
     * nombres de columna: la posición 0 de la hoja es la fila 2.
     */
    private function aplicarDiseno(Worksheet $hoja, SeccionTabla $tabla, int $ultimaPosicion): void
    {
        $diseno = $tabla->disenoHoja();
        $totalColumnas = $tabla->columnas->count();
        $dentro = fn (array $rango): bool => (int) $rango['f2'] <= $ultimaPosicion && (int) $rango['c2'] < $totalColumnas;

        foreach (array_filter($diseno['tablas'], $dentro) as $rango) {
            $colores = EstiloCelda::ESTILOS_TABLA[$rango['estilo']] ?? EstiloCelda::ESTILOS_TABLA['verde'];
            $desde = (int) $rango['f1'];

            if ($rango['encabezado'] ?? true) {
                $encabezado = $hoja->getStyle([(int) $rango['c1'] + 1, $desde + 2, (int) $rango['c2'] + 1, $desde + 2]);
                $encabezado->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim($colores['encabezado'], '#'));
                $encabezado->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
                $desde++;
            }

            if ($rango['bandas'] ?? true) {
                for ($posicion = $desde + 1; $posicion <= (int) $rango['f2']; $posicion += 2) {
                    $hoja->getStyle([(int) $rango['c1'] + 1, $posicion + 2, (int) $rango['c2'] + 1, $posicion + 2])
                        ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim($colores['banda'], '#'));
                }
            }
        }

        foreach (array_filter($diseno['combinaciones'], $dentro) as $rango) {
            $hoja->mergeCells([(int) $rango['c1'] + 1, (int) $rango['f1'] + 2, (int) $rango['c2'] + 1, (int) $rango['f2'] + 2]);
        }
    }

    /**
     * @param  Collection<int, SeccionColumna>  $columnas
     */
    private function aplicarAnchos(Worksheet $hoja, Collection $columnas): void
    {
        foreach ($columnas as $indice => $columna) {
            $dimension = $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice + 1));

            $columna->ancho
                ? $dimension->setWidth($columna->ancho / self::PX_POR_ANCHO)
                : $dimension->setAutoSize(true);
        }

        if ($columnas->isEmpty()) {
            $hoja->getColumnDimension('A')->setAutoSize(true);
        }
    }

    /**
     * Siempre texto: evita que Excel interprete "=..." como fórmula
     * (inyección de fórmulas en un archivo que otros abrirán).
     */
    private function valor(SeccionFila $fila, SeccionColumna $columna): string
    {
        return $this->normalizador->paraMostrar($fila->datos[$columna->clave] ?? null, $columna->tipo);
    }
}
