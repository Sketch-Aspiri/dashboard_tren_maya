<?php

namespace App\Console\Commands;

use App\Services\Ptar\PtarHallazgosImportador;
use Illuminate\Console\Command;

/** Carga los hallazgos de las PTAR desde el oficio mensual en Word. No duplica los que ya existen. */
class CargarHallazgosPtarCommand extends Command
{
    protected $signature = 'ptar:cargar-hallazgos {archivo : Ruta del oficio .docx} {--fecha= : Fecha de detección (Y-m-d); por defecto la del oficio} {--simular : Solo muestra qué se cargaría}';

    protected $description = 'Carga las problemáticas y propuestas de las PTAR desde el oficio mensual en Word';

    public function handle(PtarHallazgosImportador $importador): int
    {
        $ruta = (string) $this->argument('archivo');

        if (! is_file($ruta)) {
            $this->error('No existe el archivo: '.$ruta);

            return self::FAILURE;
        }

        $leido = $importador->leer($ruta);
        $fecha = (string) ($this->option('fecha') ?: $leido['fecha'] ?: now('America/Cancun')->toDateString());

        foreach ($leido['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        if ($leido['hallazgos'] === []) {
            $this->error('No se encontró ninguna problemática ni propuesta en el oficio.');

            return self::FAILURE;
        }

        $this->table(['Estación', 'Tipo', 'Descripción'], array_map(fn (array $h) => [$h['estacion'], $h['tipo'], mb_substr($h['descripcion'], 0, 70)], $leido['hallazgos']));

        if ($this->option('simular')) {
            $this->info('Simulación: '.count($leido['hallazgos'])." hallazgo(s) con fecha {$fecha}; no se guardó nada.");

            return self::SUCCESS;
        }

        $resultado = $importador->aplicar($leido['hallazgos'], $fecha);

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        $this->info("Creados: {$resultado['creados']}; omitidos por existir: {$resultado['omitidos']}.");

        return self::SUCCESS;
    }
}
