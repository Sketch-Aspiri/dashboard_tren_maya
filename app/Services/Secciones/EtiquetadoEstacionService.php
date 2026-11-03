<?php

namespace App\Services\Secciones;

use App\Models\Estacion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\ResolutorEstacion;
use Illuminate\Support\Facades\DB;

/**
 * Etiqueta en bloque las filas de una tabla con su estación, leyendo el
 * nombre de la estación de una columna. Primero se previsualiza (qué valores
 * coinciden y cuáles no) y luego se aplica; la aplicación deja UN evento
 * resumen en la bitácora.
 */
final class EtiquetadoEstacionService
{
    private const CHUNK = 500;

    /**
     * @return array{coincidencias: array<string, int>, sin_coincidencia: array<string, int>, ya_etiquetadas: int, total: int}
     */
    public function previsualizar(SeccionTabla $tabla, SeccionColumna $columna, bool $sobrescribir): array
    {
        $resolutor = new ResolutorEstacion;
        $nombres = Estacion::query()->pluck('nombre', 'id');
        $coincidencias = [];
        $sinCoincidencia = [];
        $yaEtiquetadas = 0;
        $total = 0;

        $tabla->filas()->select('id', 'datos', 'estacion_id')->chunkById(self::CHUNK, function ($filas) use ($columna, $resolutor, $nombres, $sobrescribir, &$coincidencias, &$sinCoincidencia, &$yaEtiquetadas, &$total): void {
            foreach ($filas as $fila) {
                $total++;

                if ($fila->estacion_id !== null && ! $sobrescribir) {
                    $yaEtiquetadas++;

                    continue;
                }

                $valor = $fila->datos[$columna->clave] ?? null;
                $estacionId = $resolutor->resolver($valor);

                if ($estacionId !== null) {
                    $nombre = (string) $nombres->get($estacionId);
                    $coincidencias[$nombre] = ($coincidencias[$nombre] ?? 0) + 1;
                } else {
                    $texto = trim((string) (is_scalar($valor) ? $valor : '')) ?: __('(vacío)');
                    $sinCoincidencia[$texto] = ($sinCoincidencia[$texto] ?? 0) + 1;
                }
            }
        });

        ksort($coincidencias);
        arsort($sinCoincidencia);

        return [
            'coincidencias' => $coincidencias,
            'sin_coincidencia' => array_slice($sinCoincidencia, 0, 50, true),
            'ya_etiquetadas' => $yaEtiquetadas,
            'total' => $total,
        ];
    }

    /**
     * @return array{por_estacion: array<string, int>, sin_coincidencia: int}
     */
    public function aplicar(SeccionTabla $tabla, SeccionColumna $columna, bool $sobrescribir, User $usuario): array
    {
        $resolutor = new ResolutorEstacion;
        $nombres = Estacion::query()->pluck('nombre', 'id');
        $porEstacion = [];
        $sinCoincidencia = 0;

        DB::transaction(function () use ($tabla, $columna, $sobrescribir, $resolutor, $nombres, &$porEstacion, &$sinCoincidencia): void {
            $consulta = $tabla->filas()->select('id', 'datos', 'estacion_id');

            if (! $sobrescribir) {
                $consulta->whereNull('estacion_id');
            }

            $consulta->chunkById(self::CHUNK, function ($filas) use ($columna, $resolutor, $nombres, &$porEstacion, &$sinCoincidencia): void {
                $ids = [];

                foreach ($filas as $fila) {
                    $estacionId = $resolutor->resolver($fila->datos[$columna->clave] ?? null);

                    if ($estacionId === null) {
                        $sinCoincidencia++;

                        continue;
                    }

                    $ids[$estacionId][] = $fila->id;
                    $nombre = (string) $nombres->get($estacionId);
                    $porEstacion[$nombre] = ($porEstacion[$nombre] ?? 0) + 1;
                }

                foreach ($ids as $estacionId => $filaIds) {
                    SeccionFila::query()->whereIn('id', $filaIds)->toBase()->update(['estacion_id' => $estacionId]);
                    SeccionArchivo::query()->whereIn('seccion_fila_id', $filaIds)->toBase()->update(['estacion_id' => $estacionId]);
                }
            });

            // Se recuerda la columna para que las cargas posteriores etiqueten solas.
            $tabla->forceFill(['columna_estacion_clave' => $columna->clave])->save();
        });

        ksort($porEstacion);

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($tabla->seccion)
            ->event('filas_etiquetadas_estacion')
            ->withProperties([
                'tabla_id' => $tabla->id,
                'columna' => $columna->clave,
                'por_estacion' => $porEstacion,
                'sin_coincidencia' => $sinCoincidencia,
                'sobrescribir' => $sobrescribir,
            ])
            ->log('Filas de una tabla etiquetadas con su estación.');

        return ['por_estacion' => $porEstacion, 'sin_coincidencia' => $sinCoincidencia];
    }
}
