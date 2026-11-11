<?php

namespace App\Jobs;

use App\Services\Ecosistema\PuenteCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Manda un aviso (campana + Web Push) a personas de Tickets por el puente. Va en cola con reintentos: si Tickets
 * está caído el aviso no se pierde ni retrasa la acción que lo originó. `ref` viaja con cada intento para que
 * Tickets no avise dos veces si el primer intento sí llegó pero la respuesta no volvió.
 */
class EnviarAvisoTickets implements ShouldQueue
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

    public function handle(PuenteCliente $puente): void
    {
        if (! $puente->activo()) {
            return;
        }

        $respuesta = $puente->post('/interno/avisos', [
            'correos' => $this->correos,
            'titulo' => $this->titulo,
            'cuerpo' => $this->cuerpo,
            'url' => $this->url,
            'ref' => $this->ref,
        ]);

        if ($respuesta === null) {
            // Mensaje genérico: nada de secretos ni del cuerpo de la respuesta.
            throw new RuntimeException('Tickets no recibió el aviso.');
        }
    }
}
