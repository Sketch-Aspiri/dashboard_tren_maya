<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreguntarAsistenteRequest;
use App\Services\Asistente\AsistenteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Asistente de preguntas del dashboard. Responde en streaming como NDJSON
 * (una línea JSON por evento: texto, estado, fuentes, error, fin) para que
 * la respuesta aparezca mientras se genera.
 */
class AsistenteController extends Controller
{
    public function __invoke(PreguntarAsistenteRequest $request, AsistenteService $asistente): StreamedResponse|JsonResponse
    {
        Gate::authorize('usar-asistente');

        if (! $asistente->disponible()) {
            return response()->json([
                'success' => false,
                'data' => null,
                'error' => ['code' => 'ia_no_configurada', 'message' => __('El asistente no está configurado en este servidor.')],
                'meta' => null,
            ], 503);
        }

        $pregunta = $request->validated('pregunta');
        $historial = $request->historial();
        $usuario = $request->user();

        return response()->stream(function () use ($asistente, $pregunta, $historial, $usuario): void {
            $emitir = function (string $tipo, mixed $contenido): void {
                echo json_encode(['tipo' => $tipo, 'contenido' => $contenido], JSON_UNESCAPED_UNICODE)."\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $asistente->responder($pregunta, $historial, $usuario, $emitir);
            } catch (\Throwable $e) {
                Log::error('Fallo del asistente del dashboard.', ['error' => $e->getMessage(), 'clase' => $e::class]);
                $emitir('error', __('No se pudo obtener la respuesta en este momento. Intenta de nuevo en un minuto.'));
            }

            $emitir('fin', null);
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
