<?php

namespace App\Console\Commands;

use App\Models\SeccionArchivo;
use App\Services\Secciones\LibroImportacionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Carga retroactiva: los Excel que ya están en los repositorios de
 * documentos de las secciones generan una tabla por cada hoja con datos
 * (mismo servicio que la carga de libros desde la pantalla). Es idempotente:
 * una hoja ya cargada de un archivo no se vuelve a cargar, y cada carga
 * puede deshacerse desde la pestaña Cargas de la sección. Transaccional por
 * archivo; la ejecución deja un evento resumen en la bitácora.
 */
class ImportarLibrosExistentesCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'secciones:importar-libros-existentes
        {--dry-run : Solo muestra qué hojas se cargarían; no escribe nada}
        {--force : No pedir confirmación (también se omite con --no-interaction)}
        {--seccion= : Limita la carga a una sección (slug)}';

    /**
     * @var string
     */
    protected $description = 'Crea las tablas de todas las hojas de los Excel ya guardados en las secciones (idempotente).';

    public function handle(LibroImportacionService $libros): int
    {
        $simulacro = (bool) $this->option('dry-run');

        if (! $simulacro && ! $this->option('force') && $this->input->isInteractive()
            && ! $this->confirm('Esto creará tablas y filas en las secciones a partir de los Excel guardados (se puede deshacer desde Cargas). ¿Continuar?')) {
            $this->warn('Cancelado: no se escribió nada.');

            return self::SUCCESS;
        }

        $totales = ['archivos' => 0, 'hojas' => 0, 'filas' => 0, 'errores' => 0];

        $consulta = SeccionArchivo::query()
            ->whereNull('seccion_fila_id')
            ->whereIn('extension', ['xlsx', 'xls'])
            ->with('seccion')
            ->whereHas('seccion', fn ($q) => $this->option('seccion') ? $q->where('slug', (string) $this->option('seccion')) : $q)
            ->orderBy('id');

        $consulta->each(function (SeccionArchivo $archivo) use ($libros, $simulacro, &$totales): void {
            try {
                $tablas = $libros->hojasPendientes($archivo);
            } catch (ValidationException $e) {
                $this->error("Archivo {$archivo->id}: ".implode(' ', $e->validator->errors()->all()));
                $totales['errores']++;

                return;
            }

            if ($tablas === []) {
                return;
            }

            if ($simulacro) {
                $this->line(sprintf('[simulacro] %s (%s): %s', $archivo->nombre_original, $archivo->seccion->nombre, implode(', ', array_column($tablas, 'nombre'))));
                $totales['archivos']++;
                $totales['hojas'] += count($tablas);

                return;
            }

            try {
                $resumen = $libros->importarArchivoExistente($archivo, $tablas, $archivo->autor)->resumen;
            } catch (\Throwable $e) {
                Log::warning('No se pudo cargar el libro existente.', ['archivo_id' => $archivo->id, 'error' => $e->getMessage()]);
                $this->error("Archivo {$archivo->id}: no se pudo cargar (ver el registro de errores).");
                $totales['errores']++;

                return;
            }

            $totales['archivos']++;
            $totales['hojas'] += count($resumen['hojas']);
            $totales['filas'] += $resumen['nuevas'];
        });

        $this->info(sprintf('%s: %d archivo(s), %d hoja(s), %d fila(s), %d error(es).', $simulacro ? 'Simulacro' : 'Listo', ...array_values($totales)));

        if (! $simulacro) {
            activity('seccion')
                ->event('importacion_libros_existentes')
                ->withProperties($totales + ['seccion' => $this->option('seccion'), 'origen' => 'cli'])
                ->log('Carga retroactiva de libros de Excel existentes.');
        }

        return $totales['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
