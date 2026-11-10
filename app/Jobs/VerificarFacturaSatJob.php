<?php

namespace App\Jobs;

use App\Models\Factura;
use App\Services\VerificacionSatService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Consulta en el SAT el estado de una factura, en segundo plano para que la
 * carga del XML no espere al SAT. Único por factura. Si el SAT no responde,
 * la factura queda en ERROR y el comando diario la vuelve a intentar.
 */
class VerificarFacturaSatJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $facturaId) {}

    public function uniqueId(): string
    {
        return (string) $this->facturaId;
    }

    public function handle(VerificacionSatService $sat): void
    {
        $factura = Factura::query()->find($this->facturaId);

        if ($factura !== null) {
            $sat->verificar($factura);
        }
    }
}
