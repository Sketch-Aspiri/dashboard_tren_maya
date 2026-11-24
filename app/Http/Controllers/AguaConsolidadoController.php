<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarAguaRequest;
use App\Models\AguaRegistro;
use App\Services\Agua\AguaLibro;
use App\Services\Agua\AguaService;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel mensual del registro de agua (solo la Zona). */
class AguaConsolidadoController extends Controller
{
    public function __construct(private readonly AguaService $service, private readonly AguaLibro $libro) {}

    public function descargar(ConsultarAguaRequest $request): StreamedResponse
    {
        $this->authorize('gestionar', AguaRegistro::class);

        $actual = $this->service->mesActual();
        $anio = (int) ($request->validated('anio') ?? $actual['anio']);
        $mes = (int) ($request->validated('mes') ?? $actual['mes']);
        $libro = $this->libro->mes($anio, $mes, $this->service->tablero($anio, $mes));

        activity('agua')->causedBy($request->user())->withProperties(['anio' => $anio, 'mes' => $mes])->log('excel descargado');

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            sprintf('REGISTRO_AGUA_%04d_%02d.xlsx', $anio, $mes),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }
}
