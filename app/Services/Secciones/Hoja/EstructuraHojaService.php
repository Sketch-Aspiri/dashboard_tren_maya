<?php

namespace App\Services\Secciones\Hoja;

use App\Enums\TipoColumna;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\ColumnaService;
use App\Services\Secciones\NormalizadorValores;
use App\Support\AlcanceEstacion;
use App\Support\Texto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Estructura de una hoja: insertar/borrar filas y columnas y ordenarla.
 * Como en Excel, las referencias de las fórmulas y los rangos del diseño
 * (combinaciones, formato de tabla) se ajustan en la misma transacción.
 * Cada operación bloquea la hoja (lockForUpdate) para que dos cambios de
 * estructura simultáneos no calculen posiciones sobre el mismo estado.
 *
 * Las filas nuevas y las borradas pasan por Eloquent (bitácora por fila);
 * el ajuste de fórmulas y de "orden" es un efecto derivado y va en bloque.
 */
final class EstructuraHojaService
{
    private const CHUNK = 500;

    /** Separación mínima entre dos "orden" antes de renumerar la hoja. */
    private const HUECO_MINIMO = 1e-6;

    public function __construct(
        private readonly ColumnaService $columnas,
        private readonly DisenoHojaService $diseno,
        private readonly NormalizadorValores $normalizador,
    ) {}

    /**
     * Inserta $cantidad filas vacías antes de la fila $antesDe (o al final
     * si es null).
     *
     * @return list<int> ids de las filas nuevas, en orden
     */
    public function insertarFilas(SeccionTabla $tabla, ?int $antesDe, int $cantidad, User $usuario): array
    {
        return DB::transaction(function () use ($tabla, $antesDe, $cantidad, $usuario): array {
            $this->bloquear($tabla);
            $posiciones = $this->posiciones($tabla);
            $indice = $antesDe === null ? $posiciones->count() : $posiciones->search(fn (array $fila) => $fila['id'] === $antesDe);

            if ($indice === false) {
                throw ValidationException::withMessages(['antes_de' => __('La fila ya no existe. Recarga la hoja.')]);
            }

            $ordenes = $this->ordenesEntre($tabla, $posiciones, $indice, $cantidad);
            $ids = [];

            foreach ($ordenes as $orden) {
                $fila = new SeccionFila(['datos' => []]);
                $fila->forceFill([
                    'seccion_id' => $tabla->seccion_id,
                    'seccion_tabla_id' => $tabla->id,
                    'orden' => $orden,
                    // Como en FilaService: una estación etiqueta con SU estación (no da permisos).
                    'estacion_id' => AlcanceEstacion::esTotal($usuario) ? null : $usuario->estacion_id,
                    'creado_por' => $usuario->id,
                    'actualizado_por' => $usuario->id,
                ])->save();
                $ids[] = $fila->id;
            }

            if ($indice < $posiciones->count()) {
                $this->ajustarReferencias($tabla, 'fila', $indice, $cantidad);
            }

            return $ids;
        });
    }

    /**
     * Borra filas de la hoja (soft-delete, con bitácora por fila).
     *
     * @param  list<int>  $ids
     */
    public function eliminarFilas(SeccionTabla $tabla, array $ids): void
    {
        DB::transaction(function () use ($tabla, $ids): void {
            $this->bloquear($tabla);
            $posiciones = $this->posiciones($tabla)->pluck('id')->flip();
            $ids = array_values(array_unique($ids));

            foreach ($ids as $id) {
                if (! $posiciones->has($id)) {
                    throw ValidationException::withMessages(['filas' => __('Alguna fila ya no existe. Recarga la hoja.')]);
                }
            }

            SeccionFila::query()->where('seccion_tabla_id', $tabla->id)->whereIn('id', $ids)->get()
                ->each(fn (SeccionFila $fila) => $fila->delete());

            // De abajo hacia arriba: cada bloque contiguo se ajusta sin mover a los de arriba.
            foreach (array_reverse($this->bloques($ids, $posiciones->all())) as [$desde, $cantidad]) {
                $this->ajustarReferencias($tabla, 'fila', $desde, -$cantidad);
            }
        });
    }

    /**
     * Columna de texto nueva antes de $antesDe (clave) o al final.
     */
    public function insertarColumna(SeccionTabla $tabla, ?string $antesDe, ?string $nombre): SeccionColumna
    {
        return DB::transaction(function () use ($tabla, $antesDe, $nombre): SeccionColumna {
            $this->bloquear($tabla);
            $actuales = $tabla->columnas()->get();
            $indice = $antesDe === null ? $actuales->count() : $actuales->search(fn (SeccionColumna $columna) => $columna->clave === $antesDe);

            if ($indice === false) {
                throw ValidationException::withMessages(['antes_de' => __('La columna ya no existe. Recarga la hoja.')]);
            }

            $nombre = trim((string) $nombre) !== '' ? trim((string) $nombre) : __('Columna :letra', ['letra' => FormulasHoja::letrasColumna($actuales->count())]);
            $nueva = $this->columnas->crear($tabla, $nombre, TipoColumna::Texto);

            $orden = $actuales->all();
            array_splice($orden, $indice, 0, [$nueva]);
            $this->renumerarColumnas($orden);

            if ($indice < $actuales->count()) {
                $this->ajustarReferencias($tabla, 'col', $indice, 1);
            }

            return $nueva->refresh();
        });
    }

    public function eliminarColumna(SeccionColumna $columna): void
    {
        $tabla = $columna->tabla;

        DB::transaction(function () use ($tabla, $columna): void {
            $this->bloquear($tabla);
            $indice = $tabla->columnas()->pluck('id')->search($columna->id);
            $this->columnas->eliminar($columna);

            if ($indice !== false) {
                $this->ajustarReferencias($tabla, 'col', $indice, -1);
            }
        });
    }

    /**
     * Ordena físicamente la hoja por una columna (las vacías al final). Las
     * fórmulas no se reescriben (igual que en Excel, siguen apuntando a la
     * misma posición). Con celdas combinadas no se ordena.
     */
    public function ordenar(SeccionTabla $tabla, SeccionColumna $columna, string $direccion): void
    {
        DB::transaction(function () use ($tabla, $columna, $direccion): void {
            $this->bloquear($tabla);

            if ($tabla->fresh()->disenoHoja()['combinaciones'] !== []) {
                throw ValidationException::withMessages(['columna' => __('Separa las celdas combinadas antes de ordenar la hoja.')]);
            }

            $filas = $tabla->filas()->getQuery()->enOrdenDeHoja()->get(['id', 'orden', 'datos']);
            $clave = $columna->clave;
            $signo = $direccion === 'desc' ? -1 : 1;

            $ordenadas = $filas->values()->map(fn (SeccionFila $fila, int $i) => [
                'id' => $fila->id,
                'i' => $i,
                'v' => $this->valorParaOrdenar($fila->datos[$clave] ?? null, $columna->tipo),
            ])->all();

            usort($ordenadas, function (array $a, array $b) use ($signo): int {
                if ($a['v'] === null || $b['v'] === null) {
                    return ($a['v'] === null) <=> ($b['v'] === null) ?: $a['i'] <=> $b['i'];
                }

                return ($signo * ($a['v'] <=> $b['v'])) ?: $a['i'] <=> $b['i'];
            });

            $this->renumerarFilas(array_column($ordenadas, 'id'));
        });
    }

    /**
     * Filas de la hoja en su orden, con su posición efectiva.
     *
     * @return Collection<int, array{id: int, pos: float}>
     */
    private function posiciones(SeccionTabla $tabla): Collection
    {
        return $tabla->filas()->getQuery()->enOrdenDeHoja()->get(['id', 'orden'])
            ->map(fn (SeccionFila $fila): array => ['id' => $fila->id, 'pos' => $fila->posicion()])
            ->values();
    }

    /**
     * "orden" para $cantidad filas que van en la posición $indice.
     *
     * @param  Collection<int, array{id: int, pos: float}>  $posiciones
     * @return list<float>
     */
    private function ordenesEntre(SeccionTabla $tabla, Collection $posiciones, int $indice, int $cantidad): array
    {
        $total = $posiciones->count();

        if ($indice >= $total) {
            $base = $total === 0 ? 0.0 : $posiciones[$total - 1]['pos'];

            return array_map(fn (int $i): float => $base + $i, range(1, $cantidad));
        }

        $despues = $posiciones[$indice]['pos'];
        $antes = $indice > 0 ? $posiciones[$indice - 1]['pos'] : $despues - ($cantidad + 1);
        $paso = ($despues - $antes) / ($cantidad + 1);

        if ($paso < self::HUECO_MINIMO) {
            // Sin espacio entre las dos filas: se renumera toda la hoja (1, 2, 3…) y se recalcula.
            $this->renumerarFilas($posiciones->pluck('id')->all());

            return array_map(fn (int $i): float => $indice + ($i / ($cantidad + 1)), range(1, $cantidad));
        }

        return array_map(fn (int $i): float => $antes + $paso * $i, range(1, $cantidad));
    }

    /**
     * orden = 1, 2, 3… según la lista dada (sin bitácora: es el acomodo).
     *
     * @param  list<int>  $ids
     */
    private function renumerarFilas(array $ids): void
    {
        $tablaFilas = (new SeccionFila)->getTable();

        foreach (array_chunk($ids, self::CHUNK, true) as $bloque) {
            $casos = [];
            $valores = [];

            foreach ($bloque as $posicion => $id) {
                $casos[] = 'WHEN ? THEN ?';
                array_push($valores, (int) $id, $posicion + 1);
            }

            $marcadores = implode(',', array_fill(0, count($bloque), '?'));

            DB::update(
                "UPDATE {$tablaFilas} SET orden = CASE id ".implode(' ', $casos)." END WHERE id IN ({$marcadores})",
                array_merge($valores, array_map('intval', array_values($bloque))),
            );
        }
    }

    /**
     * @param  list<SeccionColumna>  $columnas
     */
    private function renumerarColumnas(array $columnas): void
    {
        foreach (array_values($columnas) as $indice => $columna) {
            if ((int) $columna->orden !== $indice + 1) {
                SeccionColumna::query()->whereKey($columna->id)->toBase()->update(['orden' => $indice + 1]);
            }
        }
    }

    /**
     * Fórmulas y diseño de la hoja tras insertar/borrar filas o columnas.
     */
    private function ajustarReferencias(SeccionTabla $tabla, string $eje, int $desde, int $delta): void
    {
        $this->diseno->desplazar($tabla->fresh(), $eje, $desde, $delta);

        SeccionFila::query()
            ->where('seccion_tabla_id', $tabla->id)
            ->where('datos', 'like', '%"=%')
            ->select(['id', 'datos'])
            ->chunkById(self::CHUNK, function ($filas) use ($eje, $desde, $delta): void {
                foreach ($filas as $fila) {
                    $datos = $fila->datos ?? [];
                    $cambio = false;

                    foreach ($datos as $clave => $valor) {
                        if (FormulasHoja::esFormula($valor)) {
                            $nuevo = FormulasHoja::desplazar($valor, $eje, $desde, $delta);
                            $cambio = $cambio || $nuevo !== $valor;
                            $datos[$clave] = $nuevo;
                        }
                    }

                    if ($cambio) {
                        SeccionFila::query()->whereKey($fila->id)->toBase()->update([
                            'datos' => json_encode($datos, JSON_UNESCAPED_UNICODE),
                            'texto_busqueda' => SeccionFila::textoBusqueda($datos),
                        ]);
                    }
                }
            });
    }

    /**
     * Bloques contiguos [desde, cantidad] de las posiciones de $ids.
     *
     * @param  list<int>  $ids
     * @param  array<int, int>  $posicionDe  id => posición
     * @return list<array{0: int, 1: int}>
     */
    private function bloques(array $ids, array $posicionDe): array
    {
        $posiciones = array_map(fn (int $id): int => $posicionDe[$id], $ids);
        sort($posiciones);
        $bloques = [];

        foreach ($posiciones as $posicion) {
            $ultimo = count($bloques) - 1;

            if ($ultimo >= 0 && $bloques[$ultimo][0] + $bloques[$ultimo][1] === $posicion) {
                $bloques[$ultimo][1]++;
            } else {
                $bloques[] = [$posicion, 1];
            }
        }

        return $bloques;
    }

    private function valorParaOrdenar(mixed $valor, TipoColumna $tipo): int|float|string|null
    {
        if ($valor === null || $valor === '' || FormulasHoja::esFormula($valor)) {
            return null;
        }

        return match (true) {
            is_int($valor) || is_float($valor) => $valor,
            is_bool($valor) => (int) $valor,
            $tipo === TipoColumna::Numero => $this->normalizador->numero($valor) ?? Texto::normalizar($valor),
            default => Texto::normalizar($valor),
        };
    }

    private function bloquear(SeccionTabla $tabla): void
    {
        SeccionTabla::query()->whereKey($tabla->id)->lockForUpdate()->first();
    }
}
