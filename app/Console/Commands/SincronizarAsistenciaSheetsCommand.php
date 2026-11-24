<?php

namespace App\Console\Commands;

use App\Services\Asistencia\SincronizadorAsistenciaSheets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escribe la asistencia del día en el Google Sheet de Coordinación. Lo programa routes/console.php en las ventanas de
 * captura; --dry-run lee la hoja y muestra qué escribiría sin tocarla. Con ASISTENCIA_SHEETS_ACTIVO=false no escribe.
 */
class SincronizarAsistenciaSheetsCommand extends Command
{
    protected $signature = 'asistencia:sincronizar-sheets
        {--modo= : manana o tarde (por omisión, según la hora de Cancún)}
        {--limite : hora límite: escribe lo que haya aunque falten estaciones}
        {--dry-run : lee la hoja y muestra lo que escribiría, sin escribir}';

    protected $description = 'Escribe en el Google Sheet de Coordinación la asistencia capturada hoy en el Dashboard';

    public function handle(SincronizadorAsistenciaSheets $sincronizador): int
    {
        $modo = $this->option('modo') ?: null;

        if ($modo !== null && ! in_array($modo, ['manana', 'tarde'], true)) {
            $this->error('--modo debe ser manana o tarde.');

            return self::INVALID;
        }

        try {
            $resultado = $sincronizador->ejecutar(null, $modo, (bool) $this->option('limite'), (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            // Solo el mensaje: nunca el rastreo, para no filtrar rutas ni credenciales.
            Log::error('asistencia:sincronizar-sheets falló: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        Cache::put('asistencia_sheets:ultimo', $resultado + ['cuando' => now()->toIso8601String()], now()->addDays(7));
        Log::info('asistencia:sincronizar-sheets', ['estado' => $resultado['estado'], 'motivo' => $resultado['motivo'] ?? null, 'escritos' => $resultado['reporte']['escritos'] ?? null]);
        $this->mostrar($resultado);

        return $resultado['estado'] === 'error' ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, mixed>  $resultado */
    private function mostrar(array $resultado): void
    {
        $this->line('Estado: '.$resultado['estado'].(isset($resultado['motivo']) ? ' — '.$resultado['motivo'] : ''));

        foreach ((array) ($resultado['estaciones_pendientes'] ?? []) as $estacion) {
            $this->line('  pendiente: '.$estacion);
        }

        $reporte = $resultado['reporte'] ?? null;

        if ($reporte === null) {
            return;
        }

        $this->line(sprintf('Hoja %s, columna %s: %d escritos de %d esperados (%d omitidos por turno).', $reporte['hoja'], $reporte['columna'] ?? '?', $reporte['escritos'] ?? 0, $reporte['esperados'] ?? 0, $reporte['omitidos_por_turno'] ?? 0));

        foreach ([
            'errores' => 'ERROR', 'avisos' => 'aviso', 'incompletos' => 'incompleto',
            'sin_match_en_google' => 'sin fila en Google', 'numero_distinto_pero_mismo_nombre' => 'número distinto',
            'en_google_pero_no_en_dashboard' => 'solo en Google',
        ] as $clave => $etiqueta) {
            foreach ((array) ($reporte[$clave] ?? []) as $dato) {
                $this->line('  '.$etiqueta.': '.(is_string($dato) ? $dato : json_encode($dato, JSON_UNESCAPED_UNICODE)));
            }
        }
    }
}
