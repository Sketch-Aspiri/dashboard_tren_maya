<?php

namespace App\Console\Commands;

use App\Services\Agua\AguaImportador;
use Illuminate\Console\Command;

/**
 * Carga el programa y los pagos de agua desde el Excel de la Zona (una hoja por mes). No pisa registros que ya existen.
 */
class CargarAguaCommand extends Command
{
    protected $signature = 'agua:cargar-programa {archivo : Ruta del Excel} {--anio= : Año de las hojas (por defecto el actual)} {--simular : Solo muestra qué se cargaría}';

    protected $description = 'Carga el programa de agua y los pagos por estación y mes desde el Excel de la Zona';

    public function handle(AguaImportador $importador): int
    {
        $ruta = (string) $this->argument('archivo');

        if (! is_file($ruta)) {
            $this->error('No existe el archivo: '.$ruta);

            return self::FAILURE;
        }

        $anio = (int) ($this->option('anio') ?: now('America/Cancun')->year);
        $leido = $importador->leer($ruta, $anio);

        foreach ($leido['errores'] as $e) {
            $this->warn($e);
        }

        foreach ($leido['sin_estacion'] as $nombre) {
            $this->warn('Estación no reconocida (se omite): '.$nombre);
        }

        $this->table(
            ['Mes', 'Estación', 'Frecuencia', 'Consumo', 'Costo', 'Pagos', 'Total pagado', 'Cuota', 'Estado'],
            collect($leido['registros'])->map(fn (array $r) => [
                $r['mes'], $r['estacion_id'], $r['frecuencia'], $r['consumo_mensual'], $r['costo_unitario'],
                count($r['pagos']), number_format(array_sum($r['pagos']), 2), number_format($r['consumo_mensual'] * $r['costo_unitario'], 2), $r['estado'],
            ])->all(),
        );

        if ($this->option('simular')) {
            $this->info('Simulación: se leyeron '.count($leido['registros']).' registros; no se guardó nada.');

            return self::SUCCESS;
        }

        $r = $importador->aplicar($leido);
        $this->info("Creados {$r['creados']} registros con {$r['compras']} compras; {$r['omitidos']} ya existían.");

        return self::SUCCESS;
    }
}
