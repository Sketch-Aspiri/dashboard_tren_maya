<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerarOficioPtarRequest;
use App\Models\PtarHallazgo;
use App\Services\Ptar\PtarOficio;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Oficio mensual de estatus de las PTAR en Word: la Zona captura los datos y descarga el .docx. */
class PtarOficioController extends Controller
{
    public function __construct(private readonly PtarOficio $oficio) {}

    public function create(Request $request): View
    {
        $this->authorize('gestionar', PtarHallazgo::class);

        return view('ptar.oficio', [
            'secciones' => $this->oficio->secciones(),
            'valores' => [
                'leyenda' => config('ptar.oficio.leyenda'),
                'numero' => config('ptar.oficio.prefijo_numero').'/',
                'fecha' => now('America/Cancun')->toDateString(),
                'destinatario' => config('ptar.oficio.destinatario'),
                'asunto' => config('ptar.oficio.asunto'),
                'consideraciones' => implode("\n\n", config('ptar.oficio.consideraciones')),
                'firmante_cargo' => config('ptar.oficio.firmante_cargo'),
                'firmante_nombre' => config('ptar.oficio.firmante_nombre'),
            ],
        ]);
    }

    public function store(GenerarOficioPtarRequest $request): BinaryFileResponse
    {
        $datos = $request->validated();
        $datos['fecha'] = CarbonImmutable::parse($datos['fecha']);
        $datos['consideraciones'] = array_values(array_filter(array_map('trim', preg_split('/\R{2,}/u', (string) ($datos['consideraciones'] ?? '')) ?: [])));

        $destino = tempnam(sys_get_temp_dir(), 'ptar').'.docx';
        $this->oficio->generar($datos, $this->oficio->secciones(), $destino);

        activity('ptar')->causedBy($request->user())->withProperties(['numero' => $datos['numero']])->log('oficio generado');

        return response()->download($destino, 'ESTATUS_PTAR_'.$datos['fecha']->format('dmy').'.docx')->deleteFileAfterSend(true);
    }
}
