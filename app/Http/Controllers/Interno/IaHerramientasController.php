<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\IaEjecutarRequest;
use App\Http\Requests\Interno\IaHerramientasRequest;
use App\Services\Ecosistema\IaHerramientas;
use Illuminate\Http\JsonResponse;

/**
 * Herramientas para IA por el puente con Tickets: listar las disponibles y ejecutar una. Controlador delgado: la
 * lógica y las reglas viven en IaHerramientas y CapturaAsistenciaIa.
 */
class IaHerramientasController extends Controller
{
    public function index(IaHerramientasRequest $request, IaHerramientas $ia): JsonResponse
    {
        return response()->json(['herramientas' => $ia->definiciones()]);
    }

    public function ejecutar(IaEjecutarRequest $request, IaHerramientas $ia, string $nombre): JsonResponse
    {
        if (! $ia->existe($nombre)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json($ia->ejecutar($nombre, (array) $request->validated('entrada', []), (string) $request->validated('cliente')));
    }
}
