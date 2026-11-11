<?php

namespace App\Services\Ecosistema;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente del puente hacia Tickets. Si Tickets no responde, tarda o contesta
 * algo inesperado devuelve null: la pantalla sigue funcionando sin esos datos.
 * Nunca registra secretos.
 */
final class PuenteCliente
{
    public function __construct(private readonly FirmaPuente $firma) {}

    public function activo(): bool
    {
        return $this->firma->salidaConfigurada();
    }

    /**
     * @param  array<string, scalar>  $consulta
     * @return array<string, mixed>|null
     */
    public function get(string $ruta, array $consulta = []): ?array
    {
        if (! $this->activo()) {
            return null;
        }

        $ruta = '/'.ltrim($ruta, '/');
        ksort($consulta);
        $url = config('ecosistema.puente.url').$ruta.($consulta === [] ? '' : '?'.http_build_query($consulta, '', '&', PHP_QUERY_RFC3986));
        $segundos = (int) config('ecosistema.puente.timeout', 3);

        try {
            $respuesta = Http::acceptJson()
                ->timeout($segundos)
                ->connectTimeout($segundos)
                ->retry(2, 200, throw: false)
                ->withHeaders($this->firma->encabezados('GET', $ruta, $consulta))
                ->get($url);
        } catch (Throwable $e) {
            Log::warning('Puente con Tickets no disponible.', ['ruta' => $ruta, 'error' => $e::class]);

            return null;
        }

        if (! $respuesta->successful() || ! is_array($respuesta->json())) {
            Log::warning('Puente con Tickets: respuesta inesperada.', ['ruta' => $ruta, 'estado' => $respuesta->status()]);

            return null;
        }

        return $respuesta->json();
    }
}
