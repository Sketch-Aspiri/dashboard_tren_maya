<?php

namespace App\Console\Commands;

use App\Models\Factura;
use App\Services\VerificacionSatService;
use Illuminate\Console\Command;

/**
 * Vuelve a consultar al SAT las facturas vigentes, con error o sin consultar
 * (detecta cancelaciones posteriores). `--factura=ID` consulta una sola, en el
 * momento y mostrando el resultado.
 */
class VerificarFacturasSatCommand extends Command
{
    protected $signature = 'facturas:verificar-sat {--factura= : ID de una sola factura} {--anio= : Solo ese año} {--mes= : Solo ese mes}';

    protected $description = 'Consulta en el SAT si las facturas del fondo rotatorio siguen vigentes';

    public function handle(VerificacionSatService $sat): int
    {
        $facturas = $this->option('factura')
            ? Factura::query()->whereKey((int) $this->option('factura'))->get()
            : $sat->pendientes($this->option('anio') ? (int) $this->option('anio') : null, $this->option('mes') ? (int) $this->option('mes') : null);

        foreach ($facturas as $factura) {
            $this->line('#'.$factura->id.' '.$factura->referencia().' → '.$sat->verificar($factura));
            usleep(300_000); // sin saturar el servicio del SAT
        }

        $this->info($facturas->count().' factura(s) consultada(s).');

        return self::SUCCESS;
    }
}
