<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
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
    public function filas(SeccionTabla $tabla, array $filtros): LengthAwarePaginator
    {
        return $this->consultaFilas($tabla, $filtros)
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
    public function consultaFilas(SeccionTabla $tabla, array $filtros): Builder
    {
        $consulta = $tabla->filas()->getQuery();
        $termino = Texto::normalizar($filtros['q'] ?? null);

        if ($termino !== '') {
            $consulta->where('texto_busqueda', 'like', '%'.$this->escaparLike($termino).'%');
        }

        $columna = $filtros['sort'] ? $tabla->columnas->firstWhere('clave', $filtros['sort']) : null;
        $direccion = ($filtros['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        if ($columna instanceof SeccionColumna) {
            $this->ordenarPorColumna($consulta, $columna, $direccion);
        }

        // Sin orden elegido: el del documento/captura (como en el Excel original).
        return $consulta->orderBy('id', $columna ? $direccion : 'asc');
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
                $patron = '%'.$this->escaparLike($busqueda).'%';
                $q->whereFullText(['nombre_original', 'texto_extraido'], $busqueda)
                    ->orWhere('nombre_original', 'like', $patron)
                    // FULLTEXT ignora palabras cortas (folios como "A-12").
                    ->when(mb_strlen($busqueda) < 4, fn (Builder $q2) => $q2->orWhere('texto_extraido', 'like', $patron));
            });
        }

        return $consulta->limit(self::MAX_DOCUMENTOS)->get();
    }

    /**
     * JSON_EXTRACT con la ruta como parámetro enlazado; la clave ya se
     * validó contra las columnas de la tabla y la dirección es asc/desc.
     *
     * @param  Builder<SeccionFila>  $consulta
     */
    private function ordenarPorColumna(Builder $consulta, SeccionColumna $columna, string $direccion): void
    {
        $ruta = '$."'.$columna->clave.'"';
        // Un null guardado en el JSON se lee como el texto 'null': se trata
        // como vacío, y los vacíos van siempre al final.
        $valor = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(datos, ?)), 'null')";

        $expresion = $columna->tipo === TipoColumna::Numero
            ? "CAST({$valor} AS DECIMAL(30,6))"
            : "{$valor} COLLATE utf8mb4_unicode_ci"; // sin distinguir mayúsculas ni acentos

        $consulta->orderByRaw("{$valor} IS NULL, {$expresion} {$direccion}", [$ruta, $ruta]);
    }

    private function escaparLike(string $texto): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $texto);
    }
}
