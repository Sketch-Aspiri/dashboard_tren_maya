<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeerCotizacionIaRequest;
use App\Services\CotizacionService;
use App\Services\LectorCotizacionIa;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * "Leer con IA" en el formulario de cotización: devuelve, en JSON, los datos
 * que la IA propone para el PDF y la partida que más se parece a cada
 * renglón. No guarda nada: la persona revisa y guarda desde el formulario.
 */
class CotizacionIaController extends Controller
{
    public function __invoke(LeerCotizacionIaRequest $request, LectorCotizacionIa $lector, CotizacionService $service): JsonResponse
    {
        if (! $lector->disponible()) {
            return $this->error('ia_no_configurada', __('La lectura con IA no está configurada. Captura la cotización manualmente.'), 503);
        }

        try {
            $datos = $lector->leer($request->file('archivo'), $request->user());
        } catch (ValidationException $e) {
            return $this->error('ia_error', (string) collect($e->errors())->flatten()->first(), 422);
        }

        $partidas = $service->partidasDelMes((int) $request->validated('anio'), (int) $request->validated('mes'));
        $sugeridas = $service->sugerirPartidas($datos['renglones'], $partidas);

        foreach ($datos['renglones'] as $i => &$renglon) {
            $renglon['partida_id'] = $sugeridas[$i];
        }

        return response()->json(['success' => true, 'data' => $datos, 'error' => null, 'meta' => null]);
    }

    private function error(string $codigo, string $mensaje, int $estado): JsonResponse
    {
        return response()->json(['success' => false, 'data' => null, 'error' => ['code' => $codigo, 'message' => $mensaje], 'meta' => null], $estado);
    }
}
