<?php

namespace App\Jobs;

use App\Models\SeccionOrigen;
use App\Models\User;
use App\Services\Nube\NubeTransitoriaException;
use App\Services\Secciones\NubeSyncService;
use App\Services\Secciones\SincronizadorOrigen;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Sincroniza un origen de nube en segundo plano. Único por origen: si ya hay
 * uno en cola o ejecutándose, el scheduler no lo duplica. Reintenta con espera
 * creciente solo ante fallos pasajeros de la nube (429, 5xx).
 *
 * `$timeout` debe quedar por debajo de `retry_after` de la conexión de cola
 * (config/queue.php, 960) y del `--timeout` del worker (deploy/vps/).
 */
class SincronizarOrigenJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const COLA = 'nube';

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 900;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $origenId, public readonly ?int $usuarioId = null)
    {
        $this->onQueue(self::COLA);
    }

    public function uniqueId(): string
    {
        return (string) $this->origenId;
    }

    public function handle(NubeSyncService $nube, ?SincronizadorOrigen $sincronizador = null): void
    {
        $origen = SeccionOrigen::query()->activos()->find($this->origenId);

        if ($origen === null) {
            return;
        }

        // Los orígenes anteriores (un solo archivo por enlace, sin identidad remota) siguen su camino original.
        if ($origen->remoto_item_id === null) {
            $nube->sincronizarOrigen($origen);

            return;
        }

        ($sincronizador ?? app(SincronizadorOrigen::class))
            ->sincronizar($origen, $this->usuarioId === null ? null : User::query()->find($this->usuarioId));
    }

    public function failed(Throwable $e): void
    {
        $origen = SeccionOrigen::query()->find($this->origenId);

        if ($origen === null) {
            return;
        }

        // Solo los mensajes de la nube son aptos para mostrar; cualquier otra excepción queda en el log.
        $mensaje = $e instanceof NubeTransitoriaException
            ? $e->getMessage()
            : __('Error inesperado al sincronizar. Revisa el registro del servidor.');

        app(SincronizadorOrigen::class)->registrarFalloDefinitivo($origen, $mensaje);
    }
}
