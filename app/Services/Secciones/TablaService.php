<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\Reordenador;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hojas (tablas) de una sección: alta, renombrar, mover, duplicar y baja.
 * La baja hace soft-delete de filas y de la tabla y hard-delete de
 * columnas, con un solo evento de bitácora (el de la tabla); las filas se
 * borran o copian en masa sin activitylog por fila.
 */
final class TablaService
{
    private const CHUNK = 500;

    public function crear(Seccion $seccion, string $nombre): SeccionTabla
    {
        $this->validarCupo($seccion);

        return $seccion->tablas()->create([
            'nombre' => $nombre,
            'orden' => (int) $seccion->tablas()->max('orden') + 1,
        ]);
    }

    public function renombrar(SeccionTabla $tabla, string $nombre): SeccionTabla
    {
        $tabla->update(['nombre' => $nombre]);

        return $tabla;
    }

    public function mover(SeccionTabla $tabla, string $direccion): void
    {
        Reordenador::mover($tabla->seccion->tablas()->get(), $tabla, $direccion);
    }

    /**
     * Copia de la hoja (columnas, filas con su formato y el diseño) justo
     * después de la original. La copia no hereda el origen de nube ni los
     * permisos de estación: es una hoja normal, editable.
     */
    public function duplicar(SeccionTabla $tabla, string $nombre, User $usuario): SeccionTabla
    {
        $seccion = $tabla->seccion;
        $this->validarCupo($seccion);

        return DB::transaction(function () use ($tabla, $seccion, $nombre, $usuario): SeccionTabla {
            $copia = $seccion->tablas()->create([
                'nombre' => $nombre,
                'orden' => $tabla->orden,
                'diseno' => $tabla->diseno,
            ]);

            $hermanas = $seccion->tablas()->get()->reject(fn (SeccionTabla $t) => $t->is($copia))->values()->all();
            $posicion = array_search($tabla->id, array_map(fn (SeccionTabla $t) => $t->id, $hermanas), true);
            array_splice($hermanas, $posicion === false ? count($hermanas) : $posicion + 1, 0, [$copia]);

            foreach ($hermanas as $indice => $hoja) {
                SeccionTabla::query()->whereKey($hoja->id)->toBase()->update(['orden' => $indice + 1]);
            }

            foreach ($tabla->columnas as $columna) {
                $copia->columnas()->create([
                    'seccion_id' => $seccion->id,
                    'nombre' => $columna->nombre,
                    'clave' => $columna->clave,
                    'tipo' => $columna->tipo,
                    'opciones' => $columna->opciones,
                    'orden' => $columna->orden,
                    'ancho' => $columna->ancho,
                ]);
            }

            $this->copiarFilas($tabla, $copia, $usuario);

            return $copia->refresh();
        });
    }

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

    /**
     * Inserción en bloque (sin bitácora por fila: la alta de la hoja nueva
     * es el resumen). Conserva el orden de la hoja original.
     */
    private function copiarFilas(SeccionTabla $origen, SeccionTabla $destino, User $usuario): void
    {
        $posicion = 0;
        $ahora = now();

        $origen->filas()->getQuery()->enOrdenDeHoja()
            ->select(['id', 'datos', 'formato', 'orden', 'texto_busqueda', 'estacion_id'])
            ->chunk(self::CHUNK, function ($filas) use ($destino, $usuario, $ahora, &$posicion): void {
                $registros = [];

                foreach ($filas as $fila) {
                    $registros[] = [
                        'seccion_id' => $destino->seccion_id,
                        'seccion_tabla_id' => $destino->id,
                        'estacion_id' => $fila->estacion_id,
                        'datos' => json_encode($fila->datos ?? [], JSON_UNESCAPED_UNICODE),
                        'formato' => $fila->formato === null ? null : json_encode($fila->formato, JSON_UNESCAPED_UNICODE),
                        'orden' => ++$posicion,
                        'texto_busqueda' => $fila->texto_busqueda,
                        'creado_por' => $usuario->id,
                        'actualizado_por' => $usuario->id,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }

                SeccionFila::query()->toBase()->insert($registros);
            });
    }

    private function validarCupo(Seccion $seccion): void
    {
        $maximo = (int) config('secciones.max_hojas');

        if ($seccion->tablas()->count() >= $maximo) {
            throw ValidationException::withMessages([
                'nombre' => __('La sección ya tiene el máximo de :max hojas.', ['max' => $maximo]),
            ]);
        }
    }
}
