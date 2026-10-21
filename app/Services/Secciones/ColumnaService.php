<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Support\Reordenador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Definición de columnas de una tabla de una sección.
 */
final class ColumnaService
{
    private const CHUNK = 500;

    public function __construct(private readonly NormalizadorValores $normalizador) {}

    /**
     * @param  list<string>  $opciones
     */
    public function crear(SeccionTabla $tabla, string $nombre, TipoColumna $tipo, array $opciones = []): SeccionColumna
    {
        if ($tabla->columnas()->count() >= (int) config('secciones.max_columnas')) {
            throw ValidationException::withMessages([
                'nombre' => __('La tabla ya tiene el máximo de :max columnas.', ['max' => config('secciones.max_columnas')]),
            ]);
        }

        return $tabla->columnas()->create([
            'seccion_id' => $tabla->seccion_id,
            'nombre' => $nombre,
            'clave' => $this->claveUnica($tabla, $nombre),
            'tipo' => $tipo,
            'opciones' => $tipo === TipoColumna::Lista ? array_values($opciones) : null,
            'orden' => (int) $tabla->columnas()->max('orden') + 1,
        ]);
    }

    /**
     * Si cambia el tipo, los valores existentes se reconvierten (lo que no
     * se pueda convertir se conserva como texto).
     *
     * @param  list<string>  $opciones
     */
    public function actualizar(SeccionColumna $columna, string $nombre, TipoColumna $tipo, array $opciones = []): SeccionColumna
    {
        $cambioDeTipo = $columna->tipo !== $tipo;

        DB::transaction(function () use ($columna, $nombre, $tipo, $opciones, $cambioDeTipo): void {
            $columna->update([
                'nombre' => $nombre,
                'tipo' => $tipo,
                'opciones' => $tipo === TipoColumna::Lista ? array_values($opciones) : null,
            ]);

            if ($cambioDeTipo) {
                $this->reescribirValores($columna, fn ($valor) => $this->normalizador->normalizar($valor, $tipo));
            }
        });

        return $columna;
    }

    /**
     * Elimina la columna y su valor en todas las filas.
     */
    public function eliminar(SeccionColumna $columna): void
    {
        DB::transaction(function () use ($columna): void {
            $this->reescribirValores($columna, null);
            $columna->delete();
        });
    }

    public function mover(SeccionColumna $columna, string $direccion): void
    {
        Reordenador::mover($columna->tabla->columnas()->get(), $columna, $direccion);
    }

    /**
     * Clave interna snake_case única dentro de la tabla.
     *
     * @param  list<string>  $reservadas  claves ya planeadas pero aún no guardadas
     */
    public function claveUnica(SeccionTabla $tabla, string $nombre, array $reservadas = []): string
    {
        $base = Str::limit(Str::slug($nombre, '_'), 50, '') ?: 'columna';
        $existentes = array_merge($tabla->columnas()->pluck('clave')->all(), $reservadas);

        $clave = $base;
        $sufijo = 2;

        while (in_array($clave, $existentes, true)) {
            $clave = "{$base}_{$sufijo}";
            $sufijo++;
        }

        return $clave;
    }

    /**
     * Aplica $transformar al valor de la columna en cada fila (o lo quita
     * si $transformar es null), sin registrar una entrada de bitácora por
     * fila: el cambio de la columna ya queda registrado.
     */
    private function reescribirValores(SeccionColumna $columna, ?callable $transformar): void
    {
        SeccionFila::query()
            ->where('seccion_tabla_id', $columna->seccion_tabla_id)
            ->select(['id', 'datos'])
            ->chunkById(self::CHUNK, function ($filas) use ($columna, $transformar): void {
                foreach ($filas as $fila) {
                    $datos = $fila->datos ?? [];

                    if (! array_key_exists($columna->clave, $datos)) {
                        continue;
                    }

                    if ($transformar === null) {
                        unset($datos[$columna->clave]);
                    } else {
                        $datos[$columna->clave] = $transformar($datos[$columna->clave]);
                    }

                    SeccionFila::query()->whereKey($fila->id)->toBase()->update([
                        'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
                        'texto_busqueda' => SeccionFila::textoBusqueda($datos),
                    ]);
                }
            });
    }
}
