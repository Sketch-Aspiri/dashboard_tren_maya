<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\ContextoFallaRequest;
use App\Services\Ecosistema\ContextoFalla;
use Illuminate\Http\JsonResponse;

/**
 * Tickets recupera (una sola vez) el contexto de "Reportar una falla".
 */
class ContextoFallaController extends Controller
{
    public function __invoke(ContextoFallaRequest $request, ContextoFalla $contextos, string $id): JsonResponse
    {
        $contexto = $contextos->consumir($id, (string) $request->validated('correo'));

        return $contexto === null
            ? response()->json(['error' => 'not_found'], 404)
            : response()->json($contexto);
    }
}
