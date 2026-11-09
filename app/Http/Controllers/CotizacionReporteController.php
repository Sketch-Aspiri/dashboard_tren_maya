<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarCotizacionesRequest;
use App\Services\ConsolidadoInsumosService;
use App\Services\CotizacionesLibro;
use App\Services\CotizacionService;
use App\Services\SolicitudInsumoService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga del ANEXO con precios reales y el reporte de cotizaciones del mes
 * (antes el archivo *_PRECIOS_REALES.xlsx del Flujo 2A).
 */
class CotizacionReporteController extends Controller
{
    private const MESES_CORTOS = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];

    public function __invoke(
        ConsultarCotizacionesRequest $request,
        CotizacionService $cotizaciones,
        ConsolidadoInsumosService $consolidado,
        CotizacionesLibro $libro,
        SolicitudInsumoService $solicitudes,
    ): StreamedResponse {
        $objetivo = $solicitudes->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);

        $archivo = $libro->generar($consolidado->datos($anio, $mes), $cotizaciones->resumen($anio, $mes), $cotizaciones->reporte($anio, $mes));
        $nombre = 'ZO_PRECIOS_REALES_'.self::MESES_CORTOS[$mes].'_'.now()->format('dmy').'.xlsx';

        activity('cotizacion')->causedBy($request->user())->withProperties(['anio' => $anio, 'mes' => $mes])->log('precios reales exportados');

        return response()->streamDownload(function () use ($archivo) {
            (new Xlsx($archivo))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
