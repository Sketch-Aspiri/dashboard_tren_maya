<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarCalendarioVacacionRequest;
use App\Models\Estacion;
use App\Services\Vacaciones\VacacionCalendarioService;
use App\Support\AlcanceEstacion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * Calendario de las vacaciones que se van eligiendo: la Zona ve todas las estaciones; cada estación, la suya.
 */
class VacacionCalendarioController extends Controller
{
    public function __construct(private readonly VacacionCalendarioService $calendario) {}

    public function index(ConsultarCalendarioVacacionRequest $request): View
    {
        $anio = (int) config('vacaciones.anio_seleccion');
        $esZona = AlcanceEstacion::esTotal($request->user());
        $propia = $esZona ? null : AlcanceEstacion::estacionId($request->user());
        abort_if(! $esZona && $propia === null, 403);
        $mes = $request->integer('mes') ?: ($anio === (int) now('America/Cancun')->year ? (int) now('America/Cancun')->month : 1);
        $filtros = ['q' => $request->validated('q'), 'estacion' => $request->validated('estacion'), 'estado' => $request->validated('estado')];
        $primero = CarbonImmutable::create($anio, $mes, 1)->locale('es');

        return view('vacaciones.seleccion.calendario', [
            'anio' => $anio,
            'mes' => $mes,
            'titulo' => ucfirst($primero->translatedFormat('F Y')),
            'filtros' => $filtros,
            'esZona' => $esZona,
            'estacionesFiltro' => $esZona ? Estacion::query()->orderBy('orden')->get(['id', 'nombre']) : collect(),
            'datos' => $this->calendario->mes($anio, $mes, $filtros, $propia),
        ]);
    }
}
