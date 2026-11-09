<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportarConsolidadoInsumosRequest;
use App\Services\ConsolidadoInsumosLibro;
use App\Services\ConsolidadoInsumosService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del consolidado mensual de solicitudes de insumos en Excel (ANEXO,
 * LISTADO POR PRODUCTO y CONTROL_FILTRO). Solo jefatura; solo cuentan las
 * solicitudes aprobadas. Sustituye los archivos que generaba el Flujo 1 de
 * n8n.
 */
class ConsolidadoInsumosController extends Controller
{
    private const MESES_CORTOS = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];

    public function __construct(
        private readonly ConsolidadoInsumosService $service,
        private readonly ConsolidadoInsumosLibro $libro,
    ) {}

    public function descargar(ExportarConsolidadoInsumosRequest $request): StreamedResponse
    {
        $datos = $this->service->datos((int) $request->validated('anio'), (int) $request->validated('mes'));
        $tipo = $request->validated('tipo');
        $sufijo = self::MESES_CORTOS[$datos['mes']].'_'.now()->format('dmy');

        [$libro, $nombre] = match ($tipo) {
            'anexo' => [
                $this->libro->anexo($datos),
                'ZO_TJT_NÚM_'.($request->validated('oficio') ?: 'XXXX').'_'.now()->format('dmy').'_ANEXO SE REMITE PROPUESTA DE INSUMOS_'.self::MESES_CORTOS[$datos['mes']].'.xlsx',
            ],
            'listado' => [
                $this->libro->listado($datos, $this->service->listadoPorProducto($datos)),
                'SOLICITUD LISTADO POR PRODUCTO_'.$sufijo.'.xlsx',
            ],
            'control' => [
                $this->libro->control($datos, $this->service->alertas($datos)),
                'CONTROL_FILTRO_'.$sufijo.'.xlsx',
            ],
        };

        activity('solicitud_insumo')->causedBy($request->user())
            ->withProperties(['tipo' => $tipo, 'anio' => $datos['anio'], 'mes' => $datos['mes'], 'total' => $datos['total']])
            ->log('consolidado exportado');

        return $this->descarga($libro, $nombre);
    }

    private function descarga(Spreadsheet $libro, string $nombre): StreamedResponse
    {
        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
