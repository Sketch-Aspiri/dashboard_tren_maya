<?php

namespace App\Console\Commands;

use App\Services\Afluencia\AfluenciaImportador;
use Illuminate\Console\Command;

/** Carga el reporte diario de abordajes y boletos desde el Excel de la Zona. Repetirlo no duplica. */
class CargarAfluenciaCommand extends Command
{
    protected $signature = 'afluencia:importar {archivo : Ruta del Excel} {--anio= : Año de las hojas (por defecto el actual)} {--simular : Solo muestra qué se cargaría}';

    protected $description = 'Carga abordan y boletos vendidos por estación y día desde el Excel de la Zona';

    public function handle(AfluenciaImportador $importador): int
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

        foreach (array_unique($leido['sin_estacion']) as $nombre) {
            $this->warn('Estación no reconocida (se omite): '.$nombre);
        }

        $resumen = collect($leido['dias'])->groupBy(fn (array $d) => substr($d['fecha'], 0, 7).'|'.$d['estacion_id'])
            ->map(fn ($g, $k) => [...explode('|', $k), $g->count(), $g->sum('abordan'), $g->sum('boletos')])->values()->all();
        $this->table(['Mes', 'Estación (id)', 'Días', 'Abordan', 'Boletos'], $resumen);

        if ($this->option('simular')) {
            $this->info('Simulación: se leyeron '.count($leido['dias']).' días; no se guardó nada.');

            return self::SUCCESS;
        }

        $r = $importador->aplicar($leido);
        $this->info("Creados {$r['creados']} días; actualizados {$r['actualizados']}.");

        return self::SUCCESS;
    }
}
