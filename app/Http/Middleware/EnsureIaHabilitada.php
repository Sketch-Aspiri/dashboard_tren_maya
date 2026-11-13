<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las rutas `/interno/ia/*` solo existen si `ECOSISTEMA_IA` está encendido (apagado por omisión): responden 404 como si
 * no estuvieran. Va después de VerificarPuente, así que además exigen la firma del puente.
 */
class EnsureIaHabilitada
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((bool) config('ecosistema.ia.habilitada'), 404);

        return $next($request);
    }
}
