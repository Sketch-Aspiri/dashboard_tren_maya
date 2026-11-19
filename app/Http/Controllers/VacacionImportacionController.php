<?php

namespace App\Http\Controllers;

use App\Http\Requests\AplicarVacacionRequest;
use App\Http\Requests\ImportarVacacionRequest;
use App\Models\VacacionPeriodo;
use App\Services\Vacaciones\VacacionImportacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * La Zona sube el rol de vacaciones: vista previa y, al confirmar, se publica y se avisa a cada estación.
 */
class VacacionImportacionController extends Controller
{
    public function __construct(private readonly VacacionImportacionService $service) {}

    public function create(): View
    {
        $this->authorize('gestionar', VacacionPeriodo::class);

        return $this->vista(null, null);
    }

    public function store(ImportarVacacionRequest $request): RedirectResponse
    {
        $vista = $this->service->previsualizar($request->file('archivo'), $request->user());

        return redirect()->route('vacaciones.importar.vista', $vista['token']);
    }

    public function vistaPrevia(Request $request, string $token): View
    {
        $this->authorize('gestionar', VacacionPeriodo::class);

        abort_unless(Str::isUuid($token), 404);
        $resumen = $this->service->resumenPendiente($token, $request->user());
        abort_if($resumen === null, 404);

        return $this->vista($resumen, $token);
    }

    public function aplicar(AplicarVacacionRequest $request): RedirectResponse
    {
        $r = $this->service->aplicar($request->validated('token'), $request->user());
        $mensaje = __('Rol de vacaciones publicado: :personas personas en :estaciones estaciones, avisadas.', ['personas' => $r['personas'], 'estaciones' => $r['estaciones']]);

        if ($r['sin_aplicar'] > 0) {
            $mensaje .= ' '.__('Ojo: :n ajuste(s) de la versión anterior no se pudieron volver a aplicar (la persona ya no está).', ['n' => $r['sin_aplicar']]);
        }

        return redirect()->route('vacaciones.index', ['anio' => $r['periodo']->anio])->with('status', $mensaje);
    }

    public function descartar(AplicarVacacionRequest $request): RedirectResponse
    {
        $this->service->descartar($request->validated('token'));

        return redirect()->route('vacaciones.importar.create')->with('status', __('Vista previa descartada.'));
    }

    /**
     * @param  array<string, mixed>|null  $resumen
     */
    private function vista(?array $resumen, ?string $token): View
    {
        return view('vacaciones.importar', [
            'resumen' => $resumen,
            'token' => $token,
            'periodos' => VacacionPeriodo::query()->with('subidoPor:id,name')->orderByDesc('anio')->orderByDesc('version')->limit(12)->get(),
        ]);
    }
}
