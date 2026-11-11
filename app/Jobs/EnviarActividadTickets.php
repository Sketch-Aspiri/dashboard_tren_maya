<?php

namespace App\Jobs;

use App\Models\EcosistemaVinculo;
use App\Services\Ecosistema\PuenteCliente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pide a Tickets (por el puente) la actividad de un evento del Dashboard y guarda su folio y enlace para mostrar
 * «Seguimiento en Tickets». Va en cola con reintentos: si Tickets está caído no se pierde ni retrasa la acción que
 * la originó. `ref` es único: si el primer intento sí llegó pero la respuesta no volvió, Tickets devuelve la
 * misma actividad en lugar de crear otra.
 *
 * Una respuesta de rechazo (422: sin equipo configurado o sin coordinador; 403/404: puente apagado) no se
 * reintenta: no mejora esperando, se registra para corregir la configuración.
 */
class EnviarActividadTickets implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    public function __construct(
        public readonly string $evento,
        public readonly string $ref,
        public readonly ?string $estacion,
        public readonly string $titulo,
        public readonly string $descripcion,
        public readonly ?string $url,
        public readonly string $vinculableTipo,
        public readonly int $vinculableId,
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

        $respuesta = $puente->postConEstado('/interno/actividades', array_filter([
            'evento' => $this->evento,
            'estacion' => $this->estacion,
            'ref' => $this->ref,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'url' => $this->url,
        ], fn ($valor) => $valor !== null));

        if (in_array($respuesta['estado'], [200, 201], true) && is_array($respuesta['datos'])) {
            $this->guardarVinculo($respuesta['datos']);

            return;
        }

        if (in_array($respuesta['estado'], [403, 404, 422], true)) {
            // Configuración, no un fallo pasajero: sin reintentos (el motivo viene en `error`, sin datos sensibles).
            Log::warning('Tickets no creó la actividad del Dashboard.', [
                'evento' => $this->evento,
                'estado' => $respuesta['estado'],
                'motivo' => is_array($respuesta['datos']) ? ($respuesta['datos']['error'] ?? null) : null,
            ]);

            return;
        }

        // Mensaje genérico: nada de secretos ni del cuerpo de la respuesta.
        throw new RuntimeException('Tickets no recibió la solicitud de actividad.');
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function guardarVinculo(array $datos): void
    {
        $folio = is_string($datos['folio'] ?? null) && preg_match('/^[A-Z]{2,5}-\d{4}-\d{4,}$/D', $datos['folio']) === 1 ? $datos['folio'] : null;
        // Solo una ruta de actividad de Tickets; nunca otro dominio ni otra ruta.
        $ruta = is_string($datos['url'] ?? null) && preg_match('#^/activities/\d{1,12}$#D', $datos['url']) === 1 ? $datos['url'] : null;

        if ($folio === null || $ruta === null) {
            Log::warning('Tickets respondió una actividad sin folio o enlace válidos.', ['evento' => $this->evento]);

            return;
        }

        $vinculo = EcosistemaVinculo::query()->firstOrNew(['ref' => $this->ref]);
        $vinculo->fill(['evento' => $this->evento, 'folio' => $folio, 'url' => rtrim((string) config('ecosistema.urls.tickets'), '/').$ruta]);
        $vinculo->vinculable_type = $this->vinculableTipo;
        $vinculo->vinculable_id = $this->vinculableId;
        $vinculo->save();
    }
}
