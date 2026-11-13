<?php

namespace App\Http\Middleware;

use App\Services\Ecosistema\FirmaPuente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege las rutas `/interno/portal/*`: servidor a servidor, firmadas con el secreto propio del portal. Sin
 * secreto configurado no existen (404); con IPs configuradas, solo esas; las respuestas de error no dicen cuál
 * condición falló.
 */
class VerificarPuentePortal
{
    public function __construct(private readonly FirmaPuente $firma) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->firma->portalConfigurado()) {
            abort(404);
        }

        $ips = (array) config('ecosistema.puente.ips');

        if ($ips !== [] && ! in_array($request->ip(), $ips, true)) {
            return response()->json(['error' => 'forbidden'], 403);
        }

        if (! $this->firma->verificarPortal($request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        $request->attributes->set('sistema_puente', 'portal');

        $respuesta = $next($request);
        $respuesta->headers->set('Cache-Control', 'no-store, private');

        return $respuesta;
    }
}
