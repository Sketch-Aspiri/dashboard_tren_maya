<?php

namespace App\Services\Asistente;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Services\Secciones\NormalizadorValores;
use App\Support\DatosSensibles;
use App\Support\Texto;
use Illuminate\Support\Collection;

/**
 * Consulta de solo lectura sobre la tabla de una sección, para el
 * asistente: filtros + una operación (contar, listar, sumar...). Se evalúa
 * en PHP para comparar sin importar mayúsculas/acentos y respetando el
 * tipo de cada columna. Solo devuelve el resultado, nunca la tabla entera;
 * las columnas con datos personales se excluyen y todo valor listado pasa
 * por DatosSensibles::redactar().
 */
final class ConsultaFilasSeccion
{
    public const OPERADORES = ['igual', 'distinto', 'contiene', 'mayor', 'menor', 'mayor_igual', 'menor_igual', 'vacio', 'no_vacio'];

    public const OPERACIONES = ['contar', 'listar', 'sumar', 'promedio', 'minimo', 'maximo', 'contar_por_valor'];

    private const MAX_FILAS_EVALUADAS = 20000;

    private const MAX_LISTAR = 50;

    private const MAX_VALORES = 50;

    public function __construct(private readonly NormalizadorValores $normalizador) {}

    /**
     * @param  list<array{columna: string, operador: string, valor: ?string}>  $filtros
     * @return array<string, mixed>
     */
    public function ejecutar(Seccion $seccion, array $filtros, string $operacion, ?string $columnaObjetivo, int $limite): array
    {
        // Las columnas con datos personales sensibles no existen para el asistente.
        $columnas = $seccion->columnas()->get()->reject(fn (SeccionColumna $columna) => DatosSensibles::esColumnaSensible($columna->nombre))->values();
        $condiciones = array_map(fn (array $filtro) => [...$filtro, 'columna' => $this->resolverColumna($columnas, $filtro['columna'])], $filtros);
        $objetivo = $columnaObjetivo !== null ? $this->resolverColumna($columnas, $columnaObjetivo) : null;

        if (in_array($operacion, ['sumar', 'promedio', 'minimo', 'maximo', 'contar_por_valor'], true) && $objetivo === null) {
            throw new \InvalidArgumentException("La operación {$operacion} requiere columna_objetivo.");
        }

        $total = SeccionFila::query()->where('seccion_id', $seccion->id)->count();

        $coincidentes = SeccionFila::query()
            ->where('seccion_id', $seccion->id)
            ->select(['id', 'datos'])
            ->orderBy('id')
            ->lazy(1000)
            ->take(self::MAX_FILAS_EVALUADAS)
            ->filter(fn (SeccionFila $fila) => $this->cumple($fila->datos ?? [], $condiciones))
            ->map(fn (SeccionFila $fila) => $fila->datos ?? [])
            ->collect();

        return [
            'seccion' => $seccion->nombre,
            'filas_que_cumplen' => $coincidentes->count(),
            // Si la sección es más grande que el tope evaluado, el resultado es parcial.
            'parcial' => $total > self::MAX_FILAS_EVALUADAS,
            'resultado' => $this->operar($coincidentes, $operacion, $objetivo, $columnas, min(max($limite, 1), self::MAX_LISTAR)),
        ];
    }

    /**
     * @param  Collection<int, SeccionColumna>  $columnas  ya sin columnas sensibles
     */
    private function resolverColumna(Collection $columnas, string $referencia): SeccionColumna
    {
        if (DatosSensibles::esColumnaSensible($referencia)) {
            throw new \InvalidArgumentException("La columna \"{$referencia}\" contiene datos personales y no está disponible para el asistente.");
        }

        $buscada = Texto::normalizar($referencia);

        return $columnas->first(fn (SeccionColumna $columna) => $columna->clave === $referencia || Texto::normalizar($columna->nombre) === $buscada)
            ?? throw new \InvalidArgumentException("La columna \"{$referencia}\" no existe en esta sección (o contiene datos personales).");
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  list<array{columna: SeccionColumna, operador: string, valor: ?string}>  $condiciones
     */
    private function cumple(array $datos, array $condiciones): bool
    {
        foreach ($condiciones as $condicion) {
            if (! $this->evaluar($datos[$condicion['columna']->clave] ?? null, $condicion['columna']->tipo, $condicion['operador'], $condicion['valor'])) {
                return false;
            }
        }

        return true;
    }

    private function evaluar(mixed $actual, TipoColumna $tipo, string $operador, ?string $esperado): bool
    {
        $vacio = $actual === null || $actual === '';

        if ($operador === 'vacio' || $operador === 'no_vacio') {
            return $operador === 'vacio' ? $vacio : ! $vacio;
        }

        if ($vacio) {
            return $operador === 'distinto';
        }

        $a = $this->comparable($actual, $tipo);
        $b = $this->comparable($esperado, $tipo);

        return match ($operador) {
            'igual' => $a === $b,
            'distinto' => $a !== $b,
            'contiene' => str_contains(Texto::normalizar($actual), Texto::normalizar($esperado)),
            'mayor' => $a > $b,
            'menor' => $a < $b,
            'mayor_igual' => $a >= $b,
            'menor_igual' => $a <= $b,
            default => false,
        };
    }

    private function comparable(mixed $valor, TipoColumna $tipo): string|int|float|bool|null
    {
        $numero = $tipo === TipoColumna::Numero ? $this->normalizador->numero($valor) : null;

        return match ($tipo) {
            // Siempre float: 5 y 5.0 deben ser iguales.
            TipoColumna::Numero => $numero !== null ? (float) $numero : Texto::normalizar($valor),
            TipoColumna::Fecha => $this->normalizador->fecha($valor) ?? Texto::normalizar($valor),
            TipoColumna::Booleano => $this->normalizador->booleano($valor),
            default => Texto::normalizar($valor),
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @param  Collection<int, SeccionColumna>  $columnas
     */
    private function operar(Collection $filas, string $operacion, ?SeccionColumna $objetivo, Collection $columnas, int $limite): mixed
    {
        $numeros = fn () => $filas->map(fn (array $datos) => $this->normalizador->numero($datos[$objetivo->clave] ?? null))->filter(fn ($valor) => $valor !== null);

        return match ($operacion) {
            'contar' => $filas->count(),
            'listar' => $filas->take($limite)->map(fn (array $datos) => $columnas->mapWithKeys(
                fn (SeccionColumna $columna) => [$columna->nombre => DatosSensibles::redactar($this->normalizador->paraMostrar($datos[$columna->clave] ?? null, $columna->tipo))],
            )->all())->values()->all(),
            'sumar' => $numeros()->sum(),
            'promedio' => $numeros()->isEmpty() ? null : round((float) $numeros()->avg(), 4),
            'minimo' => $numeros()->min(),
            'maximo' => $numeros()->max(),
            'contar_por_valor' => $filas
                ->countBy(function (array $datos) use ($objetivo): string {
                    $texto = $this->normalizador->paraMostrar($datos[$objetivo->clave] ?? null, $objetivo->tipo);

                    return $texto === '' ? '(vacío)' : DatosSensibles::redactar($texto);
                })
                ->sortDesc()
                ->take(self::MAX_VALORES)
                ->all(),
            default => throw new \InvalidArgumentException("Operación desconocida: {$operacion}."),
        };
    }
}
