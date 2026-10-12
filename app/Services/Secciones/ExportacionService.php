<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tabla de una sección -> .xlsx (encabezados + una fila por registro).
 */
final class ExportacionService
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly ConsultaSeccionService $consulta,
        private readonly NormalizadorValores $normalizador,
    ) {}

    /**
     * @param  array{q: ?string, sort: ?string, direction: string}  $filtros
     */
    public function descargar(Seccion $seccion, array $filtros): StreamedResponse
    {
        $libro = $this->libro($seccion, $filtros);
        $nombre = Str::slug($seccion->nombre).'-'.now()->format('Y-m-d').'.xlsx';

        return response()->streamDownload(function () use ($libro): void {
            (new Xlsx($libro))->save('php://output');
            $libro->disconnectWorksheets();
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /**
     * @param  array{q: ?string, sort: ?string, direction: string}  $filtros
     */
    public function libro(Seccion $seccion, array $filtros): Spreadsheet
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle(Str::limit(preg_replace('/[\\\\\/?*\[\]:]/', ' ', $seccion->nombre) ?? 'Tabla', 28, ''));

        $columnas = $seccion->columnas;

        foreach ($columnas->values() as $indice => $columna) {
            $hoja->setCellValue([$indice + 1, 1], $columna->nombre);
        }

        $hoja->getStyle([1, 1, max(1, $columnas->count()), 1])->getFont()->setBold(true);
        $renglon = 2;

        $this->consulta->consultaFilas($seccion, $filtros)
            ->chunk(self::CHUNK, function ($filas) use ($hoja, $columnas, &$renglon): void {
                /** @var SeccionFila $fila */
                foreach ($filas as $fila) {
                    foreach ($columnas->values() as $indice => $columna) {
                        $hoja->setCellValueExplicit(
                            [$indice + 1, $renglon],
                            $this->valor($fila, $columna),
                            DataType::TYPE_STRING,
                        );
                    }

                    $renglon++;
                }
            });

        foreach (range(1, max(1, $columnas->count())) as $indice) {
            $hoja->getColumnDimension(Coordinate::stringFromColumnIndex($indice))->setAutoSize(true);
        }

        return $libro;
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
