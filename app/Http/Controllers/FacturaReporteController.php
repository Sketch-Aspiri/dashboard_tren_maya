<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarFacturasRequest;
use App\Models\Cotizacion;
use App\Models\Factura;
use App\Services\ComprobacionService;
use App\Services\FacturasLibro;
use App\Services\SolicitudInsumoService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga de COMPRA DE INSUMOS (FACTURADO, NO FACTURADO y FOLIOS) y de
 * REPORTE_COMPROBACION del mes (antes los archivos del Flujo 2B).
 */
class FacturaReporteController extends Controller
{
    private const MESES_CORTOS = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];

    public function __invoke(
        ConsultarFacturasRequest $request,
        ComprobacionService $comprobacion,
        FacturasLibro $libro,
        SolicitudInsumoService $solicitudes,
    ): StreamedResponse {
        $objetivo = $solicitudes->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);
        $tipo = $request->validated('tipo') ?? 'compra';

        $facturas = Factura::query()->with(['estacion', 'cotizacion', 'conceptos.partida.solicitud'])
            ->where('anio', $anio)->where('mes', $mes)->orderBy('fecha')->orderBy('id')->get()
            ->sortBy(fn (Factura $f) => $f->estacion?->orden ?? 999)->values();
        $noFacturado = $comprobacion->noFacturado($anio, $mes);

        if ($tipo === 'compra') {
            $archivo = $libro->compra($anio, $mes, $facturas, $noFacturado);
            $nombre = 'COMPRA DE INSUMOS_'.self::MESES_CORTOS[$mes].'.xlsx';
        } else {
            $sinFactura = Cotizacion::query()->where('anio', $anio)->where('mes', $mes)->whereDoesntHave('facturas')->orderBy('proveedor')->get()->all();
            $archivo = $libro->reporte($anio, $mes, $facturas, $comprobacion->resumen($anio, $mes), $noFacturado, $sinFactura);
            $nombre = 'REPORTE_COMPROBACION_'.self::MESES_CORTOS[$mes].'.xlsx';
        }

        activity('factura')->causedBy($request->user())->withProperties(['anio' => $anio, 'mes' => $mes, 'tipo' => $tipo])->log('comprobacion exportada');

        return response()->streamDownload(function () use ($archivo) {
            (new Xlsx($archivo))->save('php://output');
        }, $nombre, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
