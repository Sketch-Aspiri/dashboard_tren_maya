<?php

namespace App\Console\Commands;

use App\Services\Horarios\HorarioConsolidadoService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cada lunes (America/Cancun) deja listo el consolidado de horarios de la
 * semana que empieza para enviarlo a Coordinación. Se puede repetir sin
 * problema: reemplaza el archivo de esa semana.
 */
class HorariosConsolidadoSemanalCommand extends Command
{
    protected $signature = 'horarios:consolidado-semanal {--semana= : Fecha (YYYY-MM-DD) dentro de la semana a generar; por defecto la semana actual}';

    protected $description = 'Genera el consolidado semanal de horarios (lunes a domingo) para Coordinación';

    public function handle(HorarioConsolidadoService $servicio): int
    {
        try {
            $base = $this->option('semana')
                ? CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('semana'), 'America/Cancun')
                : CarbonImmutable::now('America/Cancun');

            if ($base === false) {
                $this->error('La fecha debe ser YYYY-MM-DD.');

                return self::INVALID;
            }

            $consolidado = $servicio->generar($base->startOfWeek(CarbonImmutable::MONDAY), null, avisar: true);
        } catch (Throwable $e) {
            report($e);
            $this->error('No se pudo generar el consolidado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Semana del %s: %d turnos, %d cambios → %s', $consolidado->semana_inicio->format('d/m/Y'), $consolidado->turnos, $consolidado->cambios, $consolidado->ruta));

        return self::SUCCESS;
    }
}
