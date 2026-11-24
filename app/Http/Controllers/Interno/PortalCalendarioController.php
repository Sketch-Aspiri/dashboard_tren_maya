<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\RangoCalendarioRequest;
use App\Models\VacacionPeriodo;
use App\Models\VacacionTramo;
use App\Services\Cumpleanos\CumpleanosService;
use Illuminate\Http\JsonResponse;

/**
 * Capas del calendario oficial del portal: cumpleaños y vacaciones del personal, solo lectura y por rango de fechas
 * (máximo un año). Solo nombre, estación y fechas; de los cumpleaños, únicamente la fecha en que se celebran en ese
 * rango (nunca año de nacimiento ni edad).
 */
class PortalCalendarioController extends Controller
{
    public function cumpleanos(RangoCalendarioRequest $request, CumpleanosService $servicio): JsonResponse
    {
        $desde = $request->desde();
        $hasta = $request->hasta();
        $cumpleanos = $servicio->todos();
        $salida = [];

        for ($dia = $desde; $dia <= $hasta; $dia = $dia->addDay()) {
            foreach ($cumpleanos as $c) {
                if ($servicio->celebraEn($c, $dia)) {
                    $salida[] = ['nombre' => $c['nombre'], 'estacion' => $c['estacion'], 'fecha' => $dia->format('Y-m-d')];
                }
            }
        }

        return response()->json(['cumpleanos' => $salida]);
    }

    public function vacaciones(RangoCalendarioRequest $request): JsonResponse
    {
        $desde = $request->desde();
        $hasta = $request->hasta();

        $tramos = VacacionTramo::query()
            ->whereIn('periodo_id', VacacionPeriodo::query()->where('estado', VacacionPeriodo::VIGENTE)->select('id'))
            ->whereNotNull('inicio')
            ->whereNotNull('fin')
            ->whereDate('inicio', '<=', $hasta->toDateString())
            ->whereDate('fin', '>=', $desde->toDateString())
            ->with(['persona:id,nombre', 'estacion:id,nombre'])
            ->orderBy('inicio')
            ->get();

        return response()->json(['vacaciones' => $tramos->map(fn (VacacionTramo $t): array => [
            'nombre' => (string) $t->persona?->nombre,
            'estacion' => $t->estacion?->nombre,
            'inicio' => $t->inicio->format('Y-m-d'),
            'fin' => $t->fin->format('Y-m-d'),
        ])->values()->all()]);
    }
}
