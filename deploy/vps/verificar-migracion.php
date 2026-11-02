<?php

/**
 * Compara la base y los documentos del servidor local contra el VPS.
 *
 *   1) En el servidor LOCAL (antes del dump):
 *        php deploy/vps/verificar-migracion.php manifiesto > manifiesto.json
 *   2) En el VPS (después de importar el dump y copiar storage/app/private):
 *        php deploy/vps/verificar-migracion.php verificar manifiesto.json
 *
 * Solo LEE: no modifica nada. Sale con código 1 si hay diferencias.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @return array<string,int> */
function conteos(): array
{
    $conteos = [];
    foreach (Schema::getTableListing() as $tabla) {
        $nombre = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;
        if (in_array($nombre, ['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs'], true)) {
            continue;
        }
        $conteos[$nombre] = DB::table($nombre)->count();
    }
    ksort($conteos);

    return $conteos;
}

/** @return list<string> rutas de documentos registrados que no existen en disco */
function archivosFaltantes(): array
{
    if (! Schema::hasTable('seccion_archivos')) {
        return [];
    }
    $disco = Storage::disk((string) config('secciones.disco', 'local'));
    $faltantes = [];
    DB::table('seccion_archivos')->orderBy('id')->select('id', 'ruta')->chunk(500, function ($filas) use ($disco, &$faltantes) {
        foreach ($filas as $fila) {
            if (! $disco->exists($fila->ruta)) {
                $faltantes[] = "#{$fila->id} {$fila->ruta}";
            }
        }
    });

    return $faltantes;
}

$modo = $argv[1] ?? '';

if ($modo === 'manifiesto') {
    echo json_encode(['generado' => date('c'), 'conteos' => conteos()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit(0);
}

if ($modo === 'verificar') {
    $archivo = $argv[2] ?? '';
    if (! is_file($archivo)) {
        fwrite(STDERR, "Uso: php verificar-migracion.php verificar manifiesto.json\n");
        exit(2);
    }
    $esperado = json_decode((string) file_get_contents($archivo), true)['conteos'] ?? null;
    if (! is_array($esperado)) {
        fwrite(STDERR, "manifiesto.json inválido.\n");
        exit(2);
    }

    $actual = conteos();
    $errores = [];
    foreach ($esperado as $tabla => $cuantos) {
        if (! array_key_exists($tabla, $actual)) {
            $errores[] = "Falta la tabla {$tabla}";
        } elseif ($actual[$tabla] !== $cuantos) {
            $errores[] = "{$tabla}: local={$cuantos} vps={$actual[$tabla]}";
        }
    }
    foreach (archivosFaltantes() as $falta) {
        $errores[] = "Documento sin archivo en disco: {$falta}";
    }

    if ($errores === []) {
        echo 'OK: '.count($esperado)." tablas coinciden y todos los documentos existen en disco.\n";
        exit(0);
    }
    echo "DIFERENCIAS:\n - ".implode("\n - ", $errores)."\n";
    exit(1);
}

fwrite(STDERR, "Uso: php verificar-migracion.php manifiesto|verificar [manifiesto.json]\n");
exit(2);
