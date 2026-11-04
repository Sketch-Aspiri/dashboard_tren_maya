<?php

namespace App\Console\Commands;

use App\Services\Secciones\PermisosPorNombreService;
use Illuminate\Console\Command;

class PermisosPorNombreCommand extends Command
{
    protected $signature = 'secciones:permisos-por-nombre {--simular : Solo muestra lo que cambiaría}';

    protected $description = 'Da Editar a cada estación sobre las subsecciones y hojas que la nombran (solo suma permisos).';

    public function handle(PermisosPorNombreService $servicio): int
    {
        $simular = (bool) $this->option('simular');
        $r = $servicio->aplicar($simular);

        foreach (['secciones' => 'Subsecciones', 'hojas' => 'Hojas'] as $clave => $titulo) {
            $this->info($titulo.': '.count($r[$clave]).($simular ? ' por cambiar' : ' actualizadas'));

            foreach ($r[$clave] as $fila) {
                $this->line("  [{$fila['estacion']}] {$fila['ruta']}");
            }
        }

        return self::SUCCESS;
    }
}
