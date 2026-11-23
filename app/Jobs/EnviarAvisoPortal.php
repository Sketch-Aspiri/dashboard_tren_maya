<?php

namespace App\Jobs;

use App\Services\Ecosistema\FirmaPuente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Manda un aviso al PORTAL por el puente firmado (POST /interno/subsistemas/notificaciones): el portal lo guarda en su
 * centro de notificaciones y lo entrega por Web Push (PWA del portal). Va en cola con reintentos: si el portal está
 * caído el aviso no se pierde ni retrasa la acción que lo originó. `ref` viaja igual en cada intento para que el portal
 * no avise dos veces si el primer intento sí llegó pero la respuesta no volvió. Nunca registra secretos ni el cuerpo.
 */
class EnviarAvisoPortal implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    /**
     * @param  list<string>  $correos
     */
    public function __construct(
        public readonly array $correos,
        public readonly string $titulo,
        public readonly string $cuerpo,
        public readonly string $url,
        public readonly string $ref,
        public readonly ?string $modulo = null,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(FirmaPuente $firma): void
    {
        if (! $firma->portalConfigurado()) {
            return;
        }

        $ruta = '/interno/subsistemas/notificaciones';

        try {
            $json = json_encode(array_filter([
                'correos' => $this->correos,
                'titulo' => $this->titulo,
                'cuerpo' => $this->cuerpo,
                'url' => $this->url,
                'ref' => $this->ref,
                'modulo' => $this->modulo,
            ], fn ($valor) => $valor !== null), JSON_THROW_ON_ERROR);

            $respuesta = Http::acceptJson()->timeout(10)->connectTimeout(3)->withoutRedirecting()
                ->withHeaders($firma->encabezadosPortal('POST', $ruta, [], $json))
                ->withBody($json, 'application/json')
                ->post((string) config('ecosistema.puente.portal.url').$ruta);
        } catch (JsonException) {
            return;
        } catch (Throwable) {
            // Mensaje genérico: nada de secretos ni de la respuesta.
            throw new RuntimeException('El portal no recibió el aviso.');
        }

        if (! $respuesta->successful()) {
            throw new RuntimeException('El portal no aceptó el aviso (HTTP '.$respuesta->status().').');
        }
    }
}
