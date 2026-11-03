<?php

namespace App\Services\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Support\AlcanceEstacion;
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
     * $usuario restringe a lo que ese usuario puede ver (una cuenta Estación
     * solo ve sus filas y documentos); null = sin restricción (proceso interno).
     *
     * @param  array{q: ?string, sort: ?string, direction: string, per_page: int, estacion?: ?string}  $filtros
     * @return LengthAwarePaginator<int, SeccionFila>
     */
    public function filas(SeccionTabla $tabla, array $filtros, ?User $usuario = null): LengthAwarePaginator
    {
        return $this->consultaFilas($tabla, $filtros, $usuario)
            ->with([
                'archivos' => function ($q) use ($usuario): void {
                    $q->select('id', 'seccion_fila_id', 'nombre_original', 'extension', 'estacion_id');

                    if ($usuario !== null) {
                        $q->visiblePara($usuario);
                    }
                },
                'estacion:id,nombre',
            ])
            ->paginate($filtros['per_page'])
            ->withQueryString();
    }

    /**
     * Consulta (sin paginar) con búsqueda y orden; la exportación la recorre
     * en bloques.
     *
     * @param  array{q: ?string, sort: ?string, direction: string, estacion?: ?string}  $filtros
     * @return Builder<SeccionFila>
     */
    public function consultaFilas(SeccionTabla $tabla, array $filtros, ?User $usuario = null): Builder
    {
        $consulta = $tabla->filas()->getQuery();

        if ($usuario !== null) {
            $consulta->visiblePara($usuario);
        }

        // El filtro por estación solo existe para quien lo ve todo.
        if (($usuario === null || AlcanceEstacion::esTotal($usuario)) && ($filtros['estacion'] ?? null) !== null) {
            $filtros['estacion'] === 'sin'
                ? $consulta->whereNull('estacion_id')
                : $consulta->where('estacion_id', (int) $filtros['estacion']);
        }
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
    public function documentos(Seccion $seccion, ?string $busqueda, string $orden = 'recientes', ?string $tipo = null, ?User $usuario = null, ?string $carpeta = null): Collection
    {
        $consulta = $seccion->archivos()->with(['autor:id,name', 'estacion:id,nombre', 'origen:id,proveedor,sincronizado_en,sincronizacion_error'])->latest()->latest('id');

        if ($usuario !== null) {
            $consulta->visiblePara($usuario);
        }

        $busqueda = trim((string) $busqueda);

        if ($tipo !== null && $tipo !== '') {
            $consulta->where('extension', $tipo);
        }

        // La búsqueda cubre todas las carpetas; sin ella, solo la carpeta que se está viendo.
        if ($busqueda === '') {
            $this->soloCarpeta($consulta, $carpeta ?? '');
        }

        if ($busqueda !== '') {
            $consulta->where(function (Builder $q) use ($busqueda): void {
                $patron = '%'.$this->escaparLike($busqueda).'%';
                $q->whereFullText(['nombre_original', 'texto_extraido'], $busqueda)
                    ->orWhere('nombre_original', 'like', $patron)
                    // FULLTEXT ignora palabras cortas (folios como "A-12").
                    ->when(mb_strlen($busqueda) < 4, fn (Builder $q2) => $q2->orWhere('texto_extraido', 'like', $patron));
            });
        }

        return $this->ordenarDocumentos($consulta->limit(self::MAX_DOCUMENTOS)->get(), $orden);
    }

    /**
     * Subcarpetas inmediatas de la carpeta actual, con cuántos documentos
     * (de cualquier nivel por debajo) tiene cada una.
     *
     * @return list<array{nombre: string, ruta: string, total: int}>
     */
    public function subcarpetas(Seccion $seccion, ?string $carpeta, ?User $usuario = null): array
    {
        $prefijo = ($carpeta ?? '') === '' ? '' : $carpeta.'/';
        $consulta = $seccion->archivos()->whereNotNull('remoto_ruta');

        if ($usuario !== null) {
            $consulta->visiblePara($usuario);
        }

        if ($prefijo !== '') {
            $consulta->where('remoto_ruta', 'like', $this->escaparLike($prefijo).'%');
        }

        $conteo = [];

        foreach ($consulta->pluck('remoto_ruta') as $ruta) {
            $resto = substr((string) $ruta, strlen($prefijo));

            if (str_contains($resto, '/')) {
                $nombre = strstr($resto, '/', true);
                $conteo[$nombre] = ($conteo[$nombre] ?? 0) + 1;
            }
        }

        ksort($conteo, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            fn (string $nombre): array => ['nombre' => $nombre, 'ruta' => $prefijo.$nombre, 'total' => $conteo[$nombre]],
            array_map('strval', array_keys($conteo)),
        );
    }

    /**
     * Migas de pan de una carpeta: cada nivel con su ruta acumulada.
     *
     * @return list<array{nombre: string, ruta: string}>
     */
    public function migas(?string $carpeta): array
    {
        $migas = [];
        $acumulada = '';

        foreach (array_filter(explode('/', (string) $carpeta), fn (string $parte): bool => $parte !== '') as $parte) {
            $acumulada = $acumulada === '' ? $parte : $acumulada.'/'.$parte;
            $migas[] = ['nombre' => $parte, 'ruta' => $acumulada];
        }

        return $migas;
    }

    /**
     * Deja solo los documentos de la carpeta (sin los de sus subcarpetas).
     * La raíz incluye también los documentos subidos a mano.
     */
    private function soloCarpeta(mixed $consulta, string $carpeta): void
    {
        if ($carpeta === '') {
            $consulta->where(fn (Builder $q) => $q->whereNull('remoto_ruta')->orWhere('remoto_ruta', 'not like', '%/%'));

            return;
        }

        $patron = $this->escaparLike($carpeta).'/';
        $consulta->where('remoto_ruta', 'like', $patron.'%')->where('remoto_ruta', 'not like', $patron.'%/%');
    }

    /**
     * Extensiones presentes en la sección, para el filtro por tipo.
     *
     * @return list<string>
     */
    public function tiposDeDocumento(Seccion $seccion, ?User $usuario = null): array
    {
        $consulta = $seccion->archivos();

        if ($usuario !== null) {
            $consulta->visiblePara($usuario);
        }

        return $consulta->distinct()->orderBy('extension')->pluck('extension')->all();
    }

    /**
     * Orden de los documentos ya cargados (máx. MAX_DOCUMENTOS). El nombre se
     * compara sin acentos ni mayúsculas y con orden natural ("2" antes de "10");
     * "numero" usa el número con que empieza el nombre y deja al final los
     * que no empiezan con uno.
     *
     * @param  Collection<int, SeccionArchivo>  $documentos
     * @return Collection<int, SeccionArchivo>
     */
    private function ordenarDocumentos(Collection $documentos, string $orden): Collection
    {
        $nombre = fn (SeccionArchivo $d): string => Texto::normalizar($d->nombre_original);
        $numero = function (SeccionArchivo $d): ?float {
            return preg_match('/^\s*(\d+(?:[.,]\d+)?)/u', $d->nombre_original, $m)
                ? (float) str_replace(',', '.', $m[1])
                : null;
        };

        return match ($orden) {
            'antiguos' => $documentos->sortBy(fn (SeccionArchivo $d) => [$d->created_at, $d->id])->values(),
            'nombre_asc' => $documentos->sort(fn ($a, $b) => strnatcmp($nombre($a), $nombre($b)))->values(),
            'nombre_desc' => $documentos->sort(fn ($a, $b) => strnatcmp($nombre($b), $nombre($a)))->values(),
            'numero_asc', 'numero_desc' => $documentos->sort(function ($a, $b) use ($numero, $nombre, $orden): int {
                [$na, $nb] = [$numero($a), $numero($b)];

                // Sin número siempre al final, en cualquier dirección.
                if ($na === null || $nb === null) {
                    return ($na === null) <=> ($nb === null) ?: strnatcmp($nombre($a), $nombre($b));
                }

                $cmp = $na <=> $nb ?: strnatcmp($nombre($a), $nombre($b));

                return $orden === 'numero_desc' ? -$cmp : $cmp;
            })->values(),
            'tamano_desc' => $documentos->sortByDesc('tamano')->values(),
            'tamano_asc' => $documentos->sortBy('tamano')->values(),
            default => $documentos,
        };
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
