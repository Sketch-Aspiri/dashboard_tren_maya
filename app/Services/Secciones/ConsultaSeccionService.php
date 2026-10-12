<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Support\Texto;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Lectura de la tabla y los documentos de una sección para la pantalla, la
 * exportación y el asistente.
 */
final class ConsultaSeccionService
{
    private const MAX_DOCUMENTOS = 200;

    /**
     * @param  array{q: ?string, sort: ?string, direction: string, per_page: int}  $filtros
     * @return LengthAwarePaginator<int, SeccionFila>
     */
    public function filas(Seccion $seccion, array $filtros): LengthAwarePaginator
    {
        return $this->consultaFilas($seccion, $filtros)
            ->with('archivos:id,seccion_fila_id,nombre_original,extension')
            ->paginate($filtros['per_page'])
            ->withQueryString();
    }

    /**
     * Consulta (sin paginar) con búsqueda y orden; la exportación la recorre
     * en bloques.
     *
     * @param  array{q: ?string, sort: ?string, direction: string}  $filtros
     * @return Builder<SeccionFila>
     */
    public function consultaFilas(Seccion $seccion, array $filtros): Builder
    {
        $consulta = $seccion->filas()->getQuery();
        $termino = Texto::normalizar($filtros['q'] ?? null);

        if ($termino !== '') {
            $consulta->where('texto_busqueda', 'like', '%'.$this->escaparLike($termino).'%');
        }

        $columna = $filtros['sort'] ? $seccion->columnas->firstWhere('clave', $filtros['sort']) : null;
        $direccion = ($filtros['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($columna instanceof SeccionColumna) {
            $this->ordenarPorColumna($consulta, $columna, $direccion);
        }

        return $consulta->orderBy('id', $columna ? $direccion : 'desc');
    }

    /**
     * Documentos de la sección; con búsqueda, por nombre y por contenido
     * (índice FULLTEXT sobre el texto extraído).
     *
     * @return Collection<int, SeccionArchivo>
     */
    public function documentos(Seccion $seccion, ?string $busqueda): Collection
    {
        $consulta = $seccion->archivos()->with('autor:id,name')->latest();
        $busqueda = trim((string) $busqueda);

        if ($busqueda !== '') {
            $consulta->where(function (Builder $q) use ($busqueda): void {
                $q->whereFullText(['nombre_original', 'texto_extraido'], $busqueda)
                    ->orWhere('nombre_original', 'like', '%'.$this->escaparLike($busqueda).'%');
            });
        }

        return $consulta->limit(self::MAX_DOCUMENTOS)->get();
    }

    /**
     * JSON_EXTRACT con la ruta como parámetro enlazado; la clave ya se
     * validó contra las columnas de la sección y la dirección es asc/desc.
     *
     * @param  Builder<SeccionFila>  $consulta
     */
    private function ordenarPorColumna(Builder $consulta, SeccionColumna $columna, string $direccion): void
    {
        $ruta = '$."'.$columna->clave.'"';

        if ($columna->tipo === TipoColumna::Numero) {
            $consulta->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(datos, ?)) AS DECIMAL(30,6)) {$direccion}", [$ruta]);

            return;
        }

        $consulta->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(datos, ?)) {$direccion}", [$ruta]);
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }
}
