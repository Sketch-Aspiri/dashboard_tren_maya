<?php

namespace App\Console\Commands;

use App\Models\PtarRegistro;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Services\Ecosistema\PuenteCliente;
use App\Services\Ptar\PtarService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Recuerda por la PWA de Tickets a las estaciones cuyo registro de PTAR del mes aún no está enviado (sin captura,
 * en borrador u observado). Sale a lo más una vez por estación y mes aunque el comando corra de nuevo. Sin el
 * puente configurado no hace nada.
 */
class RecordarPtarPendienteCommand extends Command
{
    protected $signature = 'ptar:recordar-pendientes';

    protected $description = 'Avisa al celular (PWA de Tickets) a las estaciones con el registro de PTAR del mes sin enviar';

    public function handle(PtarService $ptar, AvisosEcosistema $avisos, PuenteCliente $puente): int
    {
        if (! $puente->activo()) {
            $this->info('El puente con Tickets no está configurado; no se avisa a nadie.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now('America/Cancun');
        $avisadas = 0;

        foreach ($ptar->tablero($hoy->year, $hoy->month) as $fila) {
            $registro = $fila['registro'];
            if ($registro !== null && ! $registro->esEditablePorEstacion()) {
                continue;
            }

            if (! Cache::add('ecosistema:recordatorio:ptar:'.$hoy->format('Y-m').':'.$fila['estacion']->id, true, $hoy->endOfMonth()->addDay())) {
                continue;
            }

            $avisos->ptarPendiente($fila['estacion'], $registro?->estado === PtarRegistro::OBSERVADA);
            $avisadas++;
        }

        $this->info($avisadas.' estación(es) con recordatorio.');

        return self::SUCCESS;
    }
}
