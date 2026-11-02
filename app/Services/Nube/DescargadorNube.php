<?php

namespace App\Services\Nube;

use App\Services\Secciones\EnlaceNubeResolver;
use App\Services\Secciones\NubeSyncException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\UriInterface;

/**
 * Descarga segura de un enlace compartido: solo https, solo hosts permitidos
 * (también tras redirecciones), con tope de tamaño y de tiempo.
 */
final class DescargadorNube
{
    public function __construct(private readonly EnlaceNubeResolver $resolver) {}

    /**
     * @throws NubeSyncException
     */
    public function descargar(string $enlace, string $destino): void
    {
        $url = $this->resolver->resolver($enlace)['descarga'];
        $maximo = (int) config('secciones.max_archivo_kb') * 1024;

        try {
            $respuesta = Http::timeout((int) config('secciones.nube.timeout_segundos'))
                ->withHeaders(['User-Agent' => 'DashboardJefeZona-Sync/1.0'])
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

        if ($respuesta->failed()) {
            throw new NubeSyncException(__('La nube respondió con error :codigo. Revisa que el enlace siga compartido.', ['codigo' => $respuesta->status()]));
        }

        if ((int) filesize($destino) > $maximo) {
            throw new NubeSyncException($this->mensajeTamano());
        }
    }

    private function mensajeTamano(): string
    {
        return __('El archivo de la nube pesa más de :max MB.', ['max' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]);
    }
}
