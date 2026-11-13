<?php

namespace App\Console\Commands;

use App\Models\CatalogoPapeleria;
use Illuminate\Console\Command;

/**
 * Carga (idempotente) el catálogo de artículos de papelería del formato
 * oficial desde database/data/catalogo_papeleria.json.
 */
class CargarCatalogoPapeleriaCommand extends Command
{
    protected $signature = 'papeleria:cargar-catalogo';

    protected $description = 'Carga los artículos de papelería del formato oficial (no duplica ni reactiva los dados de baja)';

    public function handle(): int
    {
        $ruta = database_path('data/catalogo_papeleria.json');
        $datos = is_file($ruta) ? json_decode((string) file_get_contents($ruta), true) : null;

        if (! is_array($datos) || ! isset($datos['articulos'])) {
            $this->error('No se pudo leer '.$ruta);

            return self::FAILURE;
        }

        $nuevos = 0;

        foreach ($datos['articulos'] as $articulo) {
            $catalogo = CatalogoPapeleria::query()->firstOrCreate(['descripcion' => $articulo]);
            $nuevos += $catalogo->wasRecentlyCreated ? 1 : 0;
        }

        $this->info($nuevos.' artículos nuevos de '.count($datos['articulos']).' del archivo.');

        return self::SUCCESS;
    }
}
