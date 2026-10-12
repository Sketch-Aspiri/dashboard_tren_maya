<?php

namespace App\Services\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Support\Texto;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Calcula, SIN escribir nada, qué le haría una importación a la tabla de
 * una sección: columnas nuevas, filas nuevas, actualizadas, sin cambios y
 * eliminadas. Lo usan tanto la vista previa como la aplicación real (la
 * misma lógica en ambos casos: lo que se ve es lo que se aplica).
 */
final class PlanificadorImportacion
{
    public function __construct(
        private readonly PreparadorTabla $preparador,
        private readonly NormalizadorValores $normalizador,
        private readonly ColumnaService $columnas,
    ) {}

    /**
     * @param  list<array{nombre: string, filas: list<list<mixed>>}>  $tablas
     * @return array{
     *     columnas_nuevas: array<int, array{nombre: string, clave: string, tipo: TipoColumna}>,
     *     nuevas: list<array<string, mixed>>,
     *     actualizadas: array<int, array{antes: array<string, mixed>, despues: array<string, mixed>}>,
     *     sin_cambios: int,
     *     eliminadas: list<int>,
     *     no_vienen: int,
     * }
     */
    public function planear(Seccion $seccion, array $tablas, OpcionesImportacion $opciones): array
    {
        $filasCrudas = $tablas[$opciones->tabla]['filas'] ?? throw ValidationException::withMessages(['tabla' => __('La tabla elegida no existe en el documento.')]);

        if ($opciones->filaEncabezado < 0 || $opciones->filaEncabezado >= count($filasCrudas)) {
            throw ValidationException::withMessages(['fila_encabezado' => __('La fila de encabezados no es válida.')]);
        }

        $encabezados = $this->preparador->encabezados($filasCrudas, $opciones->filaEncabezado);
        $datos = $this->preparador->datos($filasCrudas, $opciones->filaEncabezado);

        if (count($datos) > (int) config('secciones.max_filas_importacion')) {
            throw ValidationException::withMessages(['tabla' => __('El documento trae más de :max filas.', ['max' => config('secciones.max_filas_importacion')])]);
        }

        $existentes = $seccion->columnas()->get()->keyBy('clave');
        [$destinos, $columnasNuevas] = $this->resolverDestinos($seccion, $encabezados, $opciones, $existentes);

        $tipoDe = fn (int $indice): TipoColumna => isset($columnasNuevas[$indice])
            ? $columnasNuevas[$indice]['tipo']
            : $existentes[$destinos[$indice]]->tipo;

        $entrantes = array_map(function (array $fila) use ($destinos, $tipoDe): array {
            $registro = [];

            foreach ($destinos as $indice => $clave) {
                $registro[$clave] = $this->normalizador->normalizar($fila[$indice] ?? null, $tipoDe($indice));
            }

            return $registro;
        }, $datos);

        $entrantes = array_values(array_filter($entrantes, fn (array $registro) => array_filter($registro, fn ($valor) => $valor !== null) !== []));

        $llave = $opciones->modo === ModoImportacion::Actualizar ? $destinos[$opciones->llave] : null;

        return ['columnas_nuevas' => $columnasNuevas] + $this->comparar($seccion, $entrantes, $opciones->modo, $llave);
    }

    /**
     * @param  list<string>  $encabezados
     * @param  Collection<string, SeccionColumna>  $existentes
     * @return array{0: array<int, string>, 1: array<int, array{nombre: string, clave: string, tipo: TipoColumna}>}
     */
    private function resolverDestinos(Seccion $seccion, array $encabezados, OpcionesImportacion $opciones, Collection $existentes): array
    {
        $destinos = [];
        $columnasNuevas = [];
        $reservadas = [];

        foreach ($encabezados as $indice => $nombre) {
            $destino = $opciones->mapeo[$indice] ?? OpcionesImportacion::DESTINO_IGNORAR;

            if ($destino === OpcionesImportacion::DESTINO_IGNORAR) {
                continue;
            }

            if ($destino === OpcionesImportacion::DESTINO_NUEVA) {
                $clave = $this->columnas->claveUnica($seccion, $nombre, $reservadas);
                $reservadas[] = $clave;
                $columnasNuevas[$indice] = ['nombre' => $nombre, 'clave' => $clave, 'tipo' => $opciones->tipos[$indice] ?? TipoColumna::Texto];
                $destinos[$indice] = $clave;

                continue;
            }

            if (! $existentes->has($destino) || in_array($destino, $destinos, true)) {
                throw ValidationException::withMessages(['mapeo' => __('Cada columna de la tabla solo puede recibir una columna del documento.')]);
            }

            $destinos[$indice] = $destino;
        }

        if ($destinos === []) {
            throw ValidationException::withMessages(['mapeo' => __('Elige al menos una columna para importar.')]);
        }

        if (count($columnasNuevas) + $existentes->count() > (int) config('secciones.max_columnas')) {
            throw ValidationException::withMessages(['mapeo' => __('La tabla quedaría con más de :max columnas.', ['max' => config('secciones.max_columnas')])]);
        }

        if ($opciones->modo === ModoImportacion::Actualizar && ($opciones->llave === null || ! isset($destinos[$opciones->llave]))) {
            throw ValidationException::withMessages(['llave' => __('Para actualizar elige una columna llave que sí se importe.')]);
        }

        return [$destinos, $columnasNuevas];
    }

    /**
     * @param  list<array<string, mixed>>  $entrantes
     * @return array{nuevas: list<array<string, mixed>>, actualizadas: array<int, array{antes: array<string, mixed>, despues: array<string, mixed>}>, sin_cambios: int, eliminadas: list<int>, no_vienen: int}
     */
    private function comparar(Seccion $seccion, array $entrantes, ModoImportacion $modo, ?string $llave): array
    {
        $resultado = ['nuevas' => [], 'actualizadas' => [], 'sin_cambios' => 0, 'eliminadas' => [], 'no_vienen' => 0];

        if ($modo === ModoImportacion::Agregar) {
            return ['nuevas' => $entrantes] + $resultado;
        }

        if ($modo === ModoImportacion::Reemplazar) {
            return ['nuevas' => $entrantes, 'eliminadas' => SeccionFila::query()->where('seccion_id', $seccion->id)->pluck('id')->all()] + $resultado;
        }

        $actuales = [];
        $porLlave = [];

        SeccionFila::query()->where('seccion_id', $seccion->id)->select(['id', 'datos'])->orderBy('id')
            ->each(function (SeccionFila $fila) use (&$actuales, &$porLlave, $llave): void {
                $actuales[$fila->id] = $fila->datos ?? [];
                $valorLlave = Texto::normalizar($fila->datos[$llave] ?? null);

                if ($valorLlave !== '' && ! isset($porLlave[$valorLlave])) {
                    $porLlave[$valorLlave] = $fila->id;
                }
            });

        $coincidentes = [];
        $propuestas = [];

        foreach ($entrantes as $registro) {
            $valorLlave = Texto::normalizar($registro[$llave] ?? null);
            $id = $valorLlave === '' ? null : ($porLlave[$valorLlave] ?? null);

            if ($id === null) {
                $resultado['nuevas'][] = $registro;

                continue;
            }

            $coincidentes[$id] = true;
            // Una celda vacía en el documento no borra el valor guardado.
            $propuestas[$id] = array_merge($propuestas[$id] ?? $actuales[$id], array_filter($registro, fn ($valor) => $valor !== null));
        }

        foreach ($propuestas as $id => $despues) {
            if ($this->iguales($actuales[$id], $despues)) {
                $resultado['sin_cambios']++;
            } else {
                $resultado['actualizadas'][$id] = ['antes' => $actuales[$id], 'despues' => $despues];
            }
        }

        $resultado['no_vienen'] = count($actuales) - count($coincidentes);

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function iguales(array $a, array $b): bool
    {
        foreach (array_unique([...array_keys($a), ...array_keys($b)]) as $clave) {
            if (! self::mismoValor($a[$clave] ?? null, $b[$clave] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Igualdad de dos valores guardados: estricta, salvo que 5 y 5.0 son el
     * mismo número. La usan el plan y la muestra de la vista previa, para
     * que "N actualizadas" y los ejemplos mostrados siempre coincidan.
     */
    public static function mismoValor(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return (float) $a === (float) $b;
        }

        return $a === $b;
    }
}
