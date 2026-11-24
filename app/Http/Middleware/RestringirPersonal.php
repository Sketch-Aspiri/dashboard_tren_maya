<?php

namespace App\Http\Middleware;

use App\Support\Roles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las cuentas individuales (rol Personal) solo llegan a su selección de vacaciones, su perfil, sus
 * avisos y cerrar sesión. Todo lo demás se corta aquí, aunque una ruta nueva olvide su policy.
 */
class RestringirPersonal
{
    /** Nombres de ruta exactos permitidos (nada de 'dashboard.*': ahí vive el asistente de IA). */
    private const EXACTAS = ['dashboard', 'profile.edit', 'profile.update', 'fotos.show', 'logout'];

    /** Prefijos de nombre de ruta permitidos. */
    private const PREFIJOS = ['vacaciones.mi.', 'profile.foto.', 'notificaciones.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasRole(Roles::PERSONAL)) {
            return $next($request);
        }

        $nombre = (string) $request->route()?->getName();

        if (in_array($nombre, self::EXACTAS, true)) {
            return $next($request);
        }

        foreach (self::PREFIJOS as $prefijo) {
            if (str_starts_with($nombre, $prefijo)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
