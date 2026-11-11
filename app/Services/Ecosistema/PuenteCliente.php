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
                ->withoutRedirecting()
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

    /**
     * POST JSON firmado (la firma cubre el hash del cuerpo exacto que se envía). Sin reintentos propios: quien lo
     * llama (un trabajo en cola) reintenta con una firma y un nonce nuevos.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array<string, mixed>|null
     */
    public function post(string $ruta, array $cuerpo): ?array
    {
        $respuesta = $this->postConEstado($ruta, $cuerpo);

        return $respuesta['estado'] >= 200 && $respuesta['estado'] < 300 ? $respuesta['datos'] : null;
    }

    /**
     * Igual que post(), pero devuelve también el código HTTP (0 si no hubo respuesta: puente apagado, red caída o
     * tiempo agotado) y el JSON de la respuesta aunque sea un rechazo, para decidir si conviene reintentar.
     *
     * @param  array<string, mixed>  $cuerpo
     * @return array{estado: int, datos: array<string, mixed>|null}
     */
    public function postConEstado(string $ruta, array $cuerpo): array
    {
        if (! $this->activo()) {
            return ['estado' => 0, 'datos' => null];
        }

        $ruta = '/'.ltrim($ruta, '/');
        $segundos = (int) config('ecosistema.puente.timeout', 3);

        try {
            // Dentro del try: un texto que no se pueda codificar (UTF-8 inválido) no debe lanzar una excepción sin
            // controlar desde un trabajo en cola; se trata como «no se pudo enviar».
            $json = json_encode($cuerpo, JSON_THROW_ON_ERROR);

            $respuesta = Http::acceptJson()
                ->timeout($segundos)
                ->connectTimeout($segundos)
                ->withoutRedirecting()
                ->withHeaders($this->firma->encabezados('POST', $ruta, [], $json))
                ->withBody($json, 'application/json')
                ->post(config('ecosistema.puente.url').$ruta);
        } catch (Throwable $e) {
            Log::warning('Puente con Tickets no disponible.', ['ruta' => $ruta, 'error' => $e::class]);

            return ['estado' => 0, 'datos' => null];
        }

        if (! $respuesta->successful() || ! is_array($respuesta->json())) {
            Log::warning('Puente con Tickets: respuesta inesperada.', ['ruta' => $ruta, 'estado' => $respuesta->status()]);
        }

        return ['estado' => $respuesta->status(), 'datos' => is_array($respuesta->json()) ? $respuesta->json() : null];
    }
}
