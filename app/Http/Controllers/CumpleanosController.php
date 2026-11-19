<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarCumpleanosRequest;
use App\Models\Estacion;
use App\Services\Cumpleanos\CumpleanosService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;

/**
 * Cumpleaños del personal: lo ven la Zona y las estaciones, solo día y mes.
 */
class CumpleanosController extends Controller
{
    public function __construct(private readonly CumpleanosService $service) {}

    public function index(ConsultarCumpleanosRequest $request): View
    {
        $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();
        $mes = $request->filled('mes') ? $request->integer('mes') : $hoy->month;

        $todos = $this->service->todos();
        $filtrados = $this->service->todos($request->filled('estacion') ? $request->integer('estacion') : null, $request->validated('q'));

        return view('cumpleanos.index', [
            'hoy' => $hoy,
            'mes' => $mes,
            'deHoy' => $todos->filter(fn (array $c) => $this->service->celebraEn($c, $hoy))->values(),
            'proximos' => $this->service->proximos($todos, $hoy, 30),
            'delMes' => $filtrados->filter(fn (array $c) => $c['mes'] === $mes)->sortBy('dia')->values(),
            'busqueda' => $request->filled('q') || $request->filled('estacion'),
            'resultados' => $filtrados->sortBy(fn (array $c) => sprintf('%02d%02d', $c['mes'], $c['dia']))->values(),
            'estaciones' => Estacion::query()->orderBy('orden')->get(['id', 'nombre']),
            'total' => $todos->count(),
        ]);
    }
}
