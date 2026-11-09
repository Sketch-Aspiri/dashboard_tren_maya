<?php

namespace App\Http\Controllers;

use App\Models\SolicitudInsumo;
use App\Services\ConsolidadoInsumosService;
use App\Services\FormatoSolicitudInsumoLibro;
use App\Support\Roles;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de una solicitud en el formato oficial de Excel, para firma y
 * archivo. Mismo alcance por estación que SolicitudInsumoPolicy::view. Los
 * precios solo salen para la Zona o cuando la solicitud ya está aprobada,
 * igual que en pantalla.
 */
class FormatoSolicitudInsumoController extends Controller
{
    public function __construct(private readonly FormatoSolicitudInsumoLibro $libro) {}

    public function __invoke(SolicitudInsumo $solicitud, Request $request): StreamedResponse
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['estacion', 'partidas']);

        $conPrecios = $request->user()->hasAnyRole(Roles::ZONA) || $solicitud->estado === SolicitudInsumo::APROBADA;
        $libro = $this->libro->generar($solicitud, $conPrecios);

        $nombre = ($solicitud->estacion->codigo ?? '00').'_'.Str::studly(Str::ascii($solicitud->estacion->nombre))
            .'_SOLICITUD_DE_INSUMOS_PARA_MANTENIMIENTO_'.ConsolidadoInsumosService::MESES[$solicitud->mes].'_'.$solicitud->anio.'.xlsx';

        activity('solicitud_insumo')->performedOn($solicitud)->causedBy($request->user())
            ->withProperties(['con_precios' => $conPrecios])->log('formato exportado');

        return response()->streamDownload(function () use ($libro) {
            (new Xlsx($libro))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
