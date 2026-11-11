<?php

namespace App\Console\Commands;

use App\Services\AsistenciaCapturaService;
use App\Services\Ecosistema\AvisosEcosistema;
use App\Services\Ecosistema\PuenteCliente;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Recuerda por la PWA de Tickets a las personas de cada estación que aún no
 * captura la asistencia de hoy (con plantilla activa). Sale a lo más una vez
 * por estación y día aunque el comando corra de nuevo. Sin el puente
 * configurado no hace nada.
 */
class RecordarAsistenciaPendienteCommand extends Command
{
    protected $signature = 'asistencia:recordar-pendientes';

    protected $description = 'Avisa al celular (PWA de Tickets) a las estaciones sin asistencia capturada hoy';

    public function handle(AsistenciaCapturaService $asistencia, AvisosEcosistema $avisos, PuenteCliente $puente): int
    {
        if (! $puente->activo()) {
            $this->info('El puente con Tickets no está configurado; no se avisa a nadie.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();
        $avisadas = 0;

        foreach ($asistencia->estacionesPendientes($hoy) as $estacion) {
            if (! Cache::add('ecosistema:recordatorio:asistencia:'.$hoy->toDateString().':'.$estacion->id, true, $hoy->endOfDay()->addHour())) {
                continue;
            }

            $avisos->asistenciaPendiente($estacion);
            $avisadas++;
        }

        $this->info($avisadas.' estación(es) con recordatorio.');

        return self::SUCCESS;
    }
}
