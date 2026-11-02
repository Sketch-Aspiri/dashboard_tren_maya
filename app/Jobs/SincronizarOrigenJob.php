<?php

namespace App\Jobs;

use App\Models\SeccionOrigen;
use App\Services\Secciones\NubeSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sincroniza un origen de nube en segundo plano. Único por origen: si ya hay
 * uno en cola o ejecutándose, el scheduler no lo duplica.
 *
 * `$timeout` debe quedar por debajo de `retry_after` de la conexión de cola
 * (config/queue.php) y del `--timeout` del worker (deploy/vps/).
 */
class SincronizarOrigenJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const COLA = 'nube';

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    public function __construct(public readonly int $origenId)
    {
        $this->onQueue(self::COLA);
    }

    public function uniqueId(): string
    {
        return (string) $this->origenId;
    }

    public function handle(NubeSyncService $nube): void
    {
        $origen = SeccionOrigen::query()->activos()->find($this->origenId);

        if ($origen === null) {
            return;
        }

        $nube->sincronizarOrigen($origen);
    }
}
