<?php

namespace App\Http\Controllers;

use App\Models\HorarioConsolidado;
use App\Models\HorarioPeriodo;
use App\Services\Horarios\HorarioConsolidadoService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consolidado semanal para Coordinación. Se genera solo cada lunes; aquí se
 * descarga o se regenera. Solo la Zona.
 */
class HorarioConsolidadoController extends Controller
{
    public function __construct(private readonly HorarioConsolidadoService $service) {}

    public function index(): View
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        return view('horarios.consolidados', [
            'consolidados' => HorarioConsolidado::query()->with('generadoPor:id,name')->orderByDesc('semana_inicio')->limit(20)->get(),
            'lunes' => CarbonImmutable::now('America/Cancun')->startOfWeek(CarbonImmutable::MONDAY),
        ]);
    }

    public function generar(Request $request): RedirectResponse
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        $datos = $request->validate(['semana' => ['nullable', 'date_format:Y-m-d']]);
        $base = isset($datos['semana'])
            ? CarbonImmutable::createFromFormat('!Y-m-d', $datos['semana'])
            : CarbonImmutable::now('America/Cancun');

        $consolidado = $this->service->generar($base->startOfWeek(CarbonImmutable::MONDAY), $request->user());

        return redirect()->route('horarios.consolidados.index')
            ->with('status', __('Consolidado de la semana del :fecha generado (:turnos turnos, :cambios cambios).', [
                'fecha' => $consolidado->semana_inicio->format('d/m/Y'),
                'turnos' => $consolidado->turnos,
                'cambios' => $consolidado->cambios,
            ]));
    }

    public function descargar(HorarioConsolidado $consolidado, Request $request): StreamedResponse
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        abort_unless(Storage::disk('local')->exists($consolidado->ruta), 404);

        activity('horario')->performedOn($consolidado)->causedBy($request->user())->log('consolidado semanal descargado');

        return Storage::disk('local')->download($consolidado->ruta, $consolidado->nombre, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
