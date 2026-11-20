<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\AvatarService;
use App\Services\Ecosistema\FirmaPuente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/**
 * Avisa al portal de la foto vigente (o su retiro) para que la reparta a los demás sistemas. Siempre manda lo ÚLTIMO
 * guardado, así que un reintento tardío nunca pisa una foto más nueva. Si el portal no conoce a la persona (404) no
 * se reintenta. Nunca registra secretos ni la imagen.
 */
class EnviarFotoAlPortal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 180, 900];
    }

    public function __construct(public readonly int $userId)
    {
        $this->afterCommit();
    }

    public function handle(FirmaPuente $firma, AvatarService $avatares): void
    {
        $user = User::query()->find($this->userId);

        if ($user === null || ! $firma->portalConfigurado() || str_ends_with((string) $user->email, '.invalid')) {
            return;
        }

        $bytes = $avatares->bytes($user);
        $cuerpo = ['email' => mb_strtolower((string) $user->email)] + ($bytes === null ? [] : ['imagen' => base64_encode($bytes)]);
        $metodo = $bytes === null ? 'DELETE' : 'PUT';
        $ruta = '/interno/subsistemas/avatar';

        try {
            $json = json_encode($cuerpo, JSON_THROW_ON_ERROR);
            $respuesta = Http::acceptJson()->timeout(15)->connectTimeout(3)->withoutRedirecting()
                ->withHeaders($firma->encabezadosPortal($metodo, $ruta, [], $json))
                ->withBody($json, 'application/json')
                ->send($metodo, (string) config('ecosistema.puente.portal.url').$ruta);
        } catch (JsonException) {
            return;
        } catch (Throwable) {
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);

            return;
        }

        if ($respuesta->serverError()) {
            $this->release($this->backoff()[min($this->attempts() - 1, 2)]);
        }
    }
}
