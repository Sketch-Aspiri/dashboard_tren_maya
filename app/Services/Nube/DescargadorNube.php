<?php

namespace App\Services\Nube;

use App\Services\Secciones\EnlaceNubeResolver;
use App\Services\Secciones\NubeSyncException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * Descarga segura de un enlace compartido: solo https, solo hosts permitidos
 * (también tras redirecciones), con tope de tamaño y de tiempo.
 */
final class DescargadorNube
{
    private const CACHE_BADGER = 'nube-onedrive-badger-token';

    public function __construct(private readonly EnlaceNubeResolver $resolver) {}

    /**
     * @throws NubeSyncException
     */
    public function descargar(string $enlace, string $destino): void
    {
        $url = $this->resolver->resolver($enlace)['descarga'];
        $maximo = (int) config('secciones.max_archivo_kb') * 1024;
        $esOneDrivePersonal = $this->esDescargaOneDrivePersonal($url);

        $respuesta = $this->solicitarDescarga($url, $destino, $maximo, $esOneDrivePersonal);

        // Token Badger caducado en caché: una sola vez pedimos otro y reintentamos.
        if ($esOneDrivePersonal && $respuesta->status() === 401) {
            Cache::forget(self::CACHE_BADGER);
            $respuesta = $this->solicitarDescarga($url, $destino, $maximo, true);
        }

        if ($respuesta->failed()) {
            if ($esOneDrivePersonal && $respuesta->status() === 401) {
                throw new NubeSyncException(__('OneDrive rechazó el enlace (401). Compártelo de nuevo con "cualquiera con el enlace" (solo lectura) y vuelve a vincularlo.'));
            }

            throw new NubeSyncException(__('La nube respondió con error :codigo. Revisa que el enlace siga compartido.', ['codigo' => $respuesta->status()]));
        }

        if ((int) filesize($destino) > $maximo) {
            throw new NubeSyncException($this->mensajeTamano());
        }
    }

    private function solicitarDescarga(string $url, string $destino, int $maximo, bool $conBadger): Response
    {
        $cabeceras = ['User-Agent' => 'DashboardJefeZona-Sync/1.0'];

        if ($conBadger) {
            $cabeceras['Authorization'] = 'Badger '.$this->tokenBadgerOneDrive();
            $cabeceras['Prefer'] = 'autoredeem';
        }

        try {
            return Http::timeout((int) config('secciones.nube.timeout_segundos'))
                ->withHeaders($cabeceras)
                ->withOptions([
                    'sink' => $destino,
                    'allow_redirects' => [
                        'max' => (int) config('secciones.nube.max_redirecciones'),
                        'strict' => true,
                        'protocols' => ['https'],
                        'on_redirect' => function ($request, $respuesta, UriInterface $uri): void {
                            if (! $this->resolver->hostPermitido($uri->getHost())) {
                                throw new NubeSyncException(__('El enlace redirige a un sitio no permitido.'));
                            }
                        },
                    ],
                    // Corta la descarga en cuanto rebasa el tope, sin esperar a terminarla.
                    'progress' => function ($total, $descargado) use ($maximo): void {
                        if ($descargado > $maximo) {
                            throw new NubeSyncException($this->mensajeTamano());
                        }
                    },
                ])
                ->get($url);
        } catch (ConnectionException|RequestException $e) {
            if ($e->getPrevious() instanceof NubeSyncException) {
                throw $e->getPrevious();
            }

            throw new NubeSyncException(__('No se pudo conectar con la nube. Se reintentará en la próxima revisión.'), 0, $e);
        }
    }

    /**
     * Solo la API de shares personales (api.onedrive.com). SharePoint
     * (?download=1), Google Drive y Dropbox no usan Badger.
     */
    private function esDescargaOneDrivePersonal(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST)) === 'api.onedrive.com';
    }

    /**
     * Token anónimo Badger exigido por Microsoft para descargas de shares
     * personales. Se cachea; nunca se registra en logs.
     */
    private function tokenBadgerOneDrive(): string
    {
        $minutos = max(1, (int) config('secciones.nube.onedrive_badger.cache_minutos', 360));

        try {
            $token = Cache::remember(self::CACHE_BADGER, now()->addMinutes($minutos), function (): string {
                $url = (string) config('secciones.nube.onedrive_badger.url');
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));

                if ($host !== 'api-badgerp.svc.ms' || (parse_url($url, PHP_URL_SCHEME) !== 'https')) {
                    throw new NubeSyncException($this->mensajeBadger());
                }

                $respuesta = Http::timeout((int) config('secciones.nube.timeout_segundos'))
                    ->acceptJson()
                    ->asJson()
                    ->withOptions(['allow_redirects' => false])
                    ->post($url, [
                        'appId' => (string) config('secciones.nube.onedrive_badger.app_id'),
                    ]);

                if ($respuesta->failed()) {
                    throw new NubeSyncException($this->mensajeBadger());
                }

                $token = trim((string) $respuesta->json('token'));

                if ($token === '') {
                    throw new NubeSyncException($this->mensajeBadger());
                }

                return $token;
            });
        } catch (NubeSyncException $e) {
            Cache::forget(self::CACHE_BADGER);

            throw $e;
        } catch (Throwable $e) {
            Cache::forget(self::CACHE_BADGER);

            throw new NubeSyncException($this->mensajeBadger(), 0, $e);
        }

        return (string) $token;
    }

    private function mensajeBadger(): string
    {
        return __('No se pudo autenticar la descarga de OneDrive. Se reintentará en la próxima revisión.');
    }

    private function mensajeTamano(): string
    {
        return __('El archivo de la nube pesa más de :max MB.', ['max' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]);
    }
}
