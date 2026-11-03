<?php

namespace App\Services\Nube;

use App\Models\NubeCuenta;
use App\Services\Secciones\EnlaceNubeResolver;
use App\Services\Secciones\NubeSyncException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Cliente HTTP de las APIs de nube con una cuenta conectada:
 * - Bearer solo hacia el host de la API; NUNCA hacia los hosts de descarga
 *   prefirmada a los que redirigen (OneDrive/SharePoint), cuya URL ya trae
 *   su propia autorización.
 * - Refresca el token ante un 401 (una sola vez) y respeta 429 Retry-After.
 * - Solo https y solo hosts de secciones.nube.hosts_permitidos, también en
 *   cada redirección; timeouts y tope de tamaño por archivo.
 * Los mensajes de error no incluyen tokens ni cuerpos de respuesta.
 */
final class ClienteNube
{
    public function __construct(
        private readonly CuentaNubeService $cuentas,
        private readonly EnlaceNubeResolver $resolver,
    ) {}

    /**
     * GET/POST a una API y devuelve el JSON decodificado.
     *
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, cabeceras?: array<string, string>}  $opciones
     * @return array<string, mixed>
     *
     * @throws NubeSyncException
     */
    public function json(NubeCuenta $cuenta, string $metodo, string $url, array $opciones = []): array
    {
        $this->validarUrl($url);

        $respuesta = $this->conReintentos(
            $cuenta,
            fn (string $token): Response => $this->enviar($token, $metodo, $url, $opciones),
        );

        if (! $respuesta->successful()) {
            throw new NubeSyncException($this->mensajeError($respuesta));
        }

        $datos = $respuesta->json();

        return is_array($datos) ? $datos : [];
    }

    /**
     * Descarga a `$destino` siguiendo las redirecciones a mano: cada salto se
     * valida contra la lista de hosts y el Bearer solo viaja al host de la API.
     *
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, cabeceras?: array<string, string>}  $opciones
     *
     * @throws NubeSyncException
     */
    public function descargar(NubeCuenta $cuenta, string $metodo, string $url, string $destino, array $opciones = []): void
    {
        $this->validarUrl($url);

        $hostApi = $this->host($url);
        $actual = $url;

        for ($salto = 0; $salto <= (int) config('secciones.nube.max_redirecciones'); $salto++) {
            $respuesta = $this->host($actual) === $hostApi
                ? $this->conReintentos($cuenta, fn (string $token): Response => $this->enviar($token, $metodo, $actual, $opciones, $destino))
                : $this->enviar(null, 'GET', $actual, [], $destino);

            if ($respuesta->redirect()) {
                $actual = $this->destinoDeRedireccion($respuesta, $actual);
                $metodo = 'GET';
                $opciones = [];

                continue;
            }

            if (! $respuesta->successful()) {
                throw new NubeSyncException($this->mensajeError($respuesta));
            }

            if ((int) filesize($destino) > $this->maximoBytes()) {
                throw new NubeSyncException($this->mensajeTamano());
            }

            return;
        }

        throw new NubeSyncException(__('La descarga tiene demasiadas redirecciones.'));
    }

    /**
     * Ejecuta la petición con el token vigente; ante 401 renueva una vez y
     * reintenta; ante 429 espera Retry-After (con tope) y reintenta.
     *
     * @param  callable(string): Response  $envio
     */
    private function conReintentos(NubeCuenta $cuenta, callable $envio): Response
    {
        $renovado = false;
        $esperas = 0;

        while (true) {
            $respuesta = $envio($this->token($cuenta));

            if ($respuesta->status() === 401 && ! $renovado) {
                $renovado = true;
                $this->renovar($cuenta);

                continue;
            }

            if ($respuesta->status() === 429 && $esperas < (int) config('secciones.nube.max_reintentos_429')) {
                $esperas++;
                $segundos = min(max(1, (int) $respuesta->header('Retry-After')), (int) config('secciones.nube.max_espera_429_segundos'));
                Sleep::for($segundos)->seconds();

                continue;
            }

            return $respuesta;
        }
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, cabeceras?: array<string, string>}  $opciones
     */
    private function enviar(?string $token, string $metodo, string $url, array $opciones, ?string $destino = null): Response
    {
        $peticion = Http::timeout((int) config('secciones.nube.timeout_segundos'))
            ->withHeaders(($opciones['cabeceras'] ?? []) + ['User-Agent' => 'DashboardJefeZona-Sync/1.0'])
            ->withOptions($this->opcionesDeTransporte($destino));

        if ($token !== null) {
            $peticion = $peticion->withToken($token);
        }

        try {
            return $this->conCuerpo($peticion, $metodo, $opciones)
                ->send($metodo, $url, array_filter([
                    'query' => $opciones['query'] ?? null,
                    'json' => $opciones['json'] ?? null,
                ]));
        } catch (ConnectionException|RequestException $e) {
            if ($e->getPrevious() instanceof NubeSyncException) {
                throw $e->getPrevious();
            }

            throw new NubeSyncException(__('No se pudo conectar con la nube. Se reintentará en la próxima revisión.'));
        }
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>, cabeceras?: array<string, string>}  $opciones
     */
    private function conCuerpo(PendingRequest $peticion, string $metodo, array $opciones): PendingRequest
    {
        // Dropbox (descargas) exige un POST sin cuerpo JSON.
        if (strtoupper($metodo) === 'POST' && ! isset($opciones['json'])) {
            return $peticion->withBody('', 'text/plain; charset=utf-8');
        }

        return $peticion;
    }

    /**
     * @return array<string, mixed>
     */
    private function opcionesDeTransporte(?string $destino): array
    {
        $opciones = ['allow_redirects' => false];

        if ($destino !== null) {
            $maximo = $this->maximoBytes();
            $opciones['sink'] = $destino;
            // Corta la descarga en cuanto rebasa el tope, sin esperar a terminarla.
            $opciones['progress'] = function ($total, $descargado) use ($maximo): void {
                if ($descargado > $maximo) {
                    throw new NubeSyncException($this->mensajeTamano());
                }
            };
        }

        return $opciones;
    }

    private function token(NubeCuenta $cuenta): string
    {
        try {
            return $this->cuentas->tokenVigente($cuenta);
        } catch (NubeOAuthException $e) {
            throw new NubeSyncException($e->getMessage());
        }
    }

    private function renovar(NubeCuenta $cuenta): void
    {
        try {
            $this->cuentas->refrescar($cuenta);
        } catch (NubeOAuthException $e) {
            throw new NubeSyncException($e->getMessage());
        }
    }

    private function destinoDeRedireccion(Response $respuesta, string $actual): string
    {
        $ubicacion = trim($respuesta->header('Location'));

        if ($ubicacion === '') {
            throw new NubeSyncException(__('La nube redirigió sin indicar el destino.'));
        }

        $absoluta = preg_match('#^https?://#i', $ubicacion) === 1
            ? $ubicacion
            : 'https://'.$this->host($actual).'/'.ltrim($ubicacion, '/');

        $this->validarUrl($absoluta);

        return $absoluta;
    }

    /**
     * @throws NubeSyncException
     */
    private function validarUrl(string $url): void
    {
        $host = $this->host($url);

        $valida = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && $host !== ''
            && filter_var($host, FILTER_VALIDATE_IP) === false
            && $this->resolver->hostPermitido($host);

        if (! $valida) {
            throw new NubeSyncException(__('El enlace redirige a un sitio no permitido.'));
        }
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function maximoBytes(): int
    {
        return (int) config('secciones.max_archivo_kb') * 1024;
    }

    private function mensajeTamano(): string
    {
        return __('El archivo de la nube pesa más de :max MB.', ['max' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]);
    }

    private function mensajeError(Response $respuesta): string
    {
        return match ($respuesta->status()) {
            401 => __('La nube rechazó el acceso de la cuenta. Reconéctala desde Conexiones de nube.'),
            403 => __('La cuenta conectada no tiene permiso para ver ese elemento.'),
            404 => __('No se encontró el elemento en la nube. Revisa que siga compartido con la cuenta conectada.'),
            429 => __('La nube limitó las peticiones. Se reintentará en la próxima revisión.'),
            default => __('La nube respondió con error :codigo.', ['codigo' => $respuesta->status()]),
        };
    }
}
