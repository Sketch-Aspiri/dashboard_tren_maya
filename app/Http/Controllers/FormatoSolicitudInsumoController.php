<?php

namespace App\Http\Controllers;

use App\Models\SolicitudInsumo;
use App\Services\ConsolidadoInsumosService;
use App\Services\FormatoSolicitudInsumoLibro;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de una solicitud en el formato oficial de Excel, para firma y
 * archivo. Mismo alcance por estación que SolicitudInsumoPolicy::view. Lleva
 * los precios (la estación los ve mientras captura, para cuidar su límite).
 */
class FormatoSolicitudInsumoController extends Controller
{
    public function __construct(private readonly FormatoSolicitudInsumoLibro $libro) {}

    public function __invoke(SolicitudInsumo $solicitud, Request $request): StreamedResponse
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['estacion', 'partidas']);

        $libro = $this->libro->generar($solicitud);

        $nombre = ($solicitud->estacion->codigo ?? '00').'_'.Str::studly(Str::ascii($solicitud->estacion->nombre))
            .'_SOLICITUD_DE_INSUMOS_PARA_MANTENIMIENTO_'.ConsolidadoInsumosService::MESES[$solicitud->mes].'_'.$solicitud->anio.'.xlsx';

        activity('solicitud_insumo')->performedOn($solicitud)->causedBy($request->user())->log('formato exportado');

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
