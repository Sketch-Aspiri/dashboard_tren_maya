<?php

namespace App\Services\Ecosistema;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Reportar una falla" con contexto: el Dashboard guarda unos datos mínimos
 * de dónde estaba la persona (página, rol, estación, fecha y navegador) bajo
 * un id aleatorio y Tickets los recupera por el puente al abrir el formulario
 * de Soporte. El contexto no viaja en la URL, se entrega una sola vez y solo
 * a la misma persona (mismo correo). No lleva montos ni datos personales.
 */
final class ContextoFalla
{
    private const VIGENCIA_MINUTOS = 15;

    public function guardar(User $user, string $desde, Request $request): string
    {
        $id = Str::random(40);
        $user->loadMissing('estacion:id,nombre');

        $lineas = array_filter([
            __('Página: :url', ['url' => config('app.url').$desde]),
            __('Rol: :rol', ['rol' => $user->getRoleNames()->implode(', ') ?: '—']),
            $user->estacion ? __('Estación: :nombre', ['nombre' => $user->estacion->nombre]) : null,
            __('Fecha: :fecha', ['fecha' => now()->timezone('America/Cancun')->format('d/m/Y H:i')]),
            __('Navegador: :ua', ['ua' => Str::limit((string) $request->userAgent(), 200, '')]),
        ]);

        Cache::put($this->clave($id), [
            'correo' => mb_strtolower($user->email),
            'titulo' => Str::limit(__('Falla en el Dashboard: :ruta', ['ruta' => $desde]), 250, ''),
            'descripcion' => implode("\n", $lineas)."\n\n".__('Describe qué pasó y qué esperabas que pasara:')."\n",
        ], now()->addMinutes(self::VIGENCIA_MINUTOS));

        return $id;
    }

    /**
     * El contexto, si existe y es de esa persona; se consume en el primer intento.
     *
     * @return array{titulo: string, descripcion: string}|null
     */
    public function consumir(string $id, string $correo): ?array
    {
        $datos = Cache::pull($this->clave($id));

        if (! is_array($datos) || ! hash_equals((string) ($datos['correo'] ?? ''), mb_strtolower($correo))) {
            return null;
        }

        return ['titulo' => (string) $datos['titulo'], 'descripcion' => (string) $datos['descripcion']];
    }

    private function clave(string $id): string
    {
        return 'ecosistema:contexto:'.hash('sha256', $id);
    }
}
