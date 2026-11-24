<?php

namespace App\Console\Commands;

use App\Services\Ptar\PtarImportador;
use Illuminate\Console\Command;

/**
 * Carga el Excel de estatus de las PTAR (una hoja por mes, bloque de 5 filas por planta). No pisa registros que ya
 * existen; los meses anteriores al último quedan aprobados y el último en borrador.
 */
class CargarPtarCommand extends Command
{
    protected $signature = 'ptar:cargar-historico {archivo : Ruta del Excel} {--simular : Solo muestra qué se cargaría}';

    protected $description = 'Carga el estatus mensual de las PTAR por estación desde el Excel de la Zona';

    public function handle(PtarImportador $importador): int
    {
        $ruta = (string) $this->argument('archivo');

        if (! is_file($ruta)) {
            $this->error('No existe el archivo: '.$ruta);

            return self::FAILURE;
        }

        $leido = $importador->leer($ruta);

        foreach ($leido['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        if ($leido['registros'] === []) {
            $this->error('No se encontró ninguna hoja mensual con el formato esperado.');

            return self::FAILURE;
        }

        $this->table(
            ['Hoja', 'Estación', 'Modo', 'Equipos sin dato', 'Actividades', 'Fotos', 'Estado'],
            array_map(fn (array $r) => [
                $r['hoja'], $r['estacion'], $r['modo'],
                count(array_filter($r['equipos'], fn ($e) => $e === 'sin_dato')),
                count($r['actividades']), count($r['fotos']), $r['estado'],
            ], $leido['registros']),
        );

        if ($this->option('simular')) {
            $this->info('Simulación: '.count($leido['registros']).' registro(s); no se guardó nada.');

            return self::SUCCESS;
        }

        $resultado = $importador->aplicar($leido['registros']);

        foreach ($resultado['avisos'] as $aviso) {
            $this->warn($aviso);
        }

        $this->info("Creados: {$resultado['creados']}; omitidos por existir: {$resultado['omitidos']}; fotos: {$resultado['fotos']}.");

        return self::SUCCESS;
    }
}
