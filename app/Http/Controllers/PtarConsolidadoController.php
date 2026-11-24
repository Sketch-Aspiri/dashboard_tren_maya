<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarPtarRequest;
use App\Models\PtarRegistro;
use App\Services\Ptar\PtarLibro;
use App\Services\Ptar\PtarService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel mensual de las PTAR (solo la Zona). */
class PtarConsolidadoController extends Controller
{
    public function __construct(private readonly PtarService $service, private readonly PtarLibro $libro) {}

    public function descargar(ConsultarPtarRequest $request): StreamedResponse
    {
        $this->authorize('gestionar', PtarRegistro::class);

        $actual = $this->service->mesActual();
        $anio = (int) ($request->validated('anio') ?? $actual['anio']);
        $mes = (int) ($request->validated('mes') ?? $actual['mes']);
        $libro = $this->libro->mes($anio, $mes, $this->service->tablero($anio, $mes));

        activity('ptar')->causedBy($request->user())->withProperties(['anio' => $anio, 'mes' => $mes])->log('excel descargado');

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            sprintf('ESTATUS_PTAR_%04d_%02d.xlsx', $anio, $mes),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
