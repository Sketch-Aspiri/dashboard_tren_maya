<?php

namespace App\Http\Middleware;

use App\Services\Ecosistema\FirmaPuente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas `/interno/*` del puente con Tickets. Sin secreto
 * configurado las rutas no existen (404); con IPs configuradas, solo esas; y
 * siempre firma HMAC válida, fecha dentro de la ventana y nonce sin usar. Las
 * respuestas de error no dicen cuál condición falló.
 */
class VerificarPuente
{
    public function __construct(private readonly FirmaPuente $firma) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->firma->entradaConfigurada()) {
            abort(404);
        }

        $ips = (array) config('ecosistema.puente.ips');

        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        $sistema = $this->firma->verificar($request);

        if ($sistema === null) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('sistema_puente', $sistema);

        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}
