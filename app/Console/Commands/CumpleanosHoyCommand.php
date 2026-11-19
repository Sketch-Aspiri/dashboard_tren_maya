<?php

namespace App\Console\Commands;

use App\Services\Cumpleanos\CumpleanosService;
use App\Services\Ecosistema\AvisosEcosistema;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Avisa a todas las cuentas quién cumple años hoy (hora de Cancún). Un solo aviso por día aunque el
 * comando corra de nuevo, y ninguno si nadie cumple.
 */
class CumpleanosHoyCommand extends Command
{
    protected $signature = 'cumpleanos:avisar-hoy';

    protected $description = 'Avisa a todas las cuentas quién cumple años hoy';

    public function handle(CumpleanosService $cumpleanos, AvisosEcosistema $avisos): int
    {
        $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();
        $celebran = $cumpleanos->deHoy($hoy);

        if ($celebran->isEmpty()) {
            $this->info('Hoy no cumple años nadie.');

            return self::SUCCESS;
        }

        if (! Cache::add('ecosistema:recordatorio:cumpleanos:'.$hoy->toDateString(), true, $hoy->endOfDay()->addHour())) {
            $this->info('El aviso de hoy ya salió.');

            return self::SUCCESS;
        }

        $avisos->cumpleanosHoy($celebran->pluck('nombre')->all());
        $this->info($celebran->count().' cumpleaños avisado(s).');

        return self::SUCCESS;
    }
}
