<?php

namespace App\Services\Secciones;

use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use Illuminate\Support\Facades\DB;

/**
 * Baja de una hoja (tabla) de una sección: soft-delete de filas y de la
 * tabla, hard-delete de columnas. Un solo evento de bitácora (el de la
 * tabla); las filas se borran en masa sin activitylog por fila.
 */
final class TablaService
{
    private const CHUNK = 500;

    public function eliminar(SeccionTabla $tabla): void
    {
        DB::transaction(function () use ($tabla): void {
            $ahora = now();

            // Soft-delete por lotes (sin eventos ni activitylog por fila): el
            // evento de la tabla es el resumen en bitácora.
            SeccionFila::query()
                ->where('seccion_tabla_id', $tabla->id)
                ->select('id')
                ->chunkById(self::CHUNK, function ($filas) use ($ahora): void {
                    SeccionFila::query()->whereIn('id', $filas->pluck('id'))->toBase()
                        ->update(['deleted_at' => $ahora]);
                });

            SeccionColumna::query()->where('seccion_tabla_id', $tabla->id)->delete();
            $tabla->delete();
        });
    }
}
