<?php

namespace App\Http\Controllers;

use App\Http\Requests\AplicarHorarioRequest;
use App\Http\Requests\ImportarHorarioRequest;
use App\Models\HorarioPeriodo;
use App\Services\Horarios\HorarioImportacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Edificio sube el horario oficial: vista previa y, al confirmar, se publica y
 * se avisa a cada estación. Solo la Zona (HorarioPeriodoPolicy::gestionar).
 */
class HorarioImportacionController extends Controller
{
    public function __construct(private readonly HorarioImportacionService $service) {}

    public function create(): View
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        return view('horarios.importar', [
            'resumen' => null,
            'token' => null,
            'periodos' => HorarioPeriodo::query()->with('subidoPor:id,name')->orderByDesc('anio')->orderByDesc('mes')->orderByDesc('version')->limit(12)->get(),
        ]);
    }

    public function store(ImportarHorarioRequest $request): RedirectResponse
    {
        $vista = $this->service->previsualizar($request->file('archivo'), $request->user());

        return redirect()->route('horarios.importar.vista', $vista['token']);
    }

    public function vista(Request $request, string $token): View
    {
        $this->authorize('gestionar', HorarioPeriodo::class);

        abort_unless(Str::isUuid($token), 404);
        $resumen = $this->service->resumenPendiente($token, $request->user());
        abort_if($resumen === null, 404);

        return view('horarios.importar', [
            'resumen' => $resumen,
            'token' => $token,
            'periodos' => HorarioPeriodo::query()->with('subidoPor:id,name')->orderByDesc('anio')->orderByDesc('mes')->orderByDesc('version')->limit(12)->get(),
        ]);
    }

    public function aplicar(AplicarHorarioRequest $request): RedirectResponse
    {
        $resultado = $this->service->aplicar($request->validated('token'), $request->user());

        $mensaje = __('Horario publicado: :turnos turnos en :estaciones estaciones, avisadas.', ['turnos' => $resultado['turnos'], 'estaciones' => $resultado['estaciones']]);

        if ($resultado['sin_aplicar'] > 0) {
            $mensaje .= ' '.trans_choice('Ojo: :n cambio ya autorizado no se pudo volver a aplicar (la persona o la fecha ya no existen).|Ojo: :n cambios ya autorizados no se pudieron volver a aplicar (la persona o la fecha ya no existen).', $resultado['sin_aplicar'], ['n' => $resultado['sin_aplicar']]);
        }

        return redirect()->route('horarios.index', ['anio' => $resultado['periodo']->anio, 'mes' => $resultado['periodo']->mes])->with('status', $mensaje);
    }

    public function descartar(AplicarHorarioRequest $request): RedirectResponse
    {
        $this->service->descartar($request->validated('token'));

        return redirect()->route('horarios.importar.create')->with('status', __('Vista previa descartada.'));
    }
}
