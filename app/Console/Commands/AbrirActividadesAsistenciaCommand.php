<?php

namespace App\Console\Commands;

use App\Services\AsistenciaCapturaService;
use App\Services\Ecosistema\ActividadesEcosistema;
use App\Services\Ecosistema\PuenteCliente;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * A la hora límite, pide a Tickets una actividad por cada estación que aún no captura la asistencia de hoy (con
 * plantilla activa). Sale a lo más una vez por estación y día aunque el comando corra de nuevo. Sin el puente o
 * con el evento apagado no hace nada.
 */
class AbrirActividadesAsistenciaCommand extends Command
{
    protected $signature = 'asistencia:abrir-actividades';

    protected $description = 'Pide a Tickets una actividad por estación sin asistencia capturada a la hora límite';

    public function handle(AsistenciaCapturaService $asistencia, ActividadesEcosistema $actividades, PuenteCliente $puente): int
    {
        if (! $puente->activo() || ! $actividades->habilitado('asistencia_pendiente')) {
            $this->info('El puente con Tickets o el evento «asistencia_pendiente» no están activos; no se pide ninguna actividad.');

            return self::SUCCESS;
        }

        $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();
        $pedidas = 0;

        foreach ($asistencia->estacionesPendientes($hoy) as $estacion) {
            if (! Cache::add('ecosistema:actividad:asistencia:'.$hoy->toDateString().':'.$estacion->id, true, $hoy->endOfDay()->addHour())) {
                continue;
            }

            $actividades->asistenciaPendiente($estacion, $hoy);
            $pedidas++;
        }

        $this->info($pedidas.' estación(es) con actividad pedida.');

        return self::SUCCESS;
    }
}
