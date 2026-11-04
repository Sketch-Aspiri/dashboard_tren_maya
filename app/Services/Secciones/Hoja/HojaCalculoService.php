<?php

namespace App\Services\Secciones\Hoja;

use App\Enums\TipoColumna;
use App\Models\SeccionColumna;
use App\Models\SeccionFila;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\NormalizadorValores;
use App\Support\EstiloCelda;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lectura de una hoja completa para la cuadrícula tipo Excel y guardado de
 * celdas por lote (edición, pegado, formato, deshacer). Cada fila tocada se
 * guarda con Eloquent: queda en la bitácora vía LogsActivity de SeccionFila.
 */
final class HojaCalculoService
{
    public const MAX_FORMULA = 2000;

    public function __construct(private readonly NormalizadorValores $normalizador) {}

    /**
     * Toda la hoja (hasta config('secciones.hoja_max_filas') filas, en el
     * orden de la hoja). Solo lo que el usuario puede ver.
     *
     * @return array<string, mixed>
     */
    public function datos(SeccionTabla $tabla, User $usuario): array
    {
        $limite = (int) config('secciones.hoja_max_filas');

        $filas = $tabla->filas()->getQuery()
            ->visiblePara($usuario)
            ->enOrdenDeHoja()
            ->with('estacion:id,nombre')
            ->limit($limite + 1)
            ->get(['id', 'orden', 'datos', 'formato', 'estacion_id']);

        $truncada = $filas->count() > $limite;

        return [
            'columnas' => $tabla->columnas->map(fn (SeccionColumna $columna): array => [
                'id' => $columna->id,
                'clave' => $columna->clave,
                'nombre' => $columna->nombre,
                'tipo' => $columna->tipo->value,
                'opciones' => $columna->tipo === TipoColumna::Lista ? $columna->listaOpciones() : [],
                'ancho' => $columna->ancho,
            ])->values()->all(),
            'filas' => $filas->take($limite)->map(fn (SeccionFila $fila): array => [
                'id' => $fila->id,
                'datos' => (object) ($fila->datos ?? []),
                'formato' => (object) ($fila->formato ?? []),
                'estacion' => $fila->estacion?->nombre,
                'estacion_id' => $fila->estacion_id,
            ])->values()->all(),
            'diseno' => $tabla->disenoHoja(),
            'truncada' => $truncada,
        ];
    }

    /**
     * Guarda un lote de cambios de celda. Cada cambio trae "fila" (id),
     * "columna" (clave) y "valor" y/o "formato" (el estilo completo de la
     * celda; null = sin estilo). Todo o nada.
     *
     * @param  list<array{fila: int, columna: string, valor?: mixed, formato?: array<string, mixed>|null}>  $cambios
     * @return array<int, array{datos: object, formato: object}> filas tocadas, por id
     */
    public function guardarCeldas(SeccionTabla $tabla, array $cambios, User $usuario): array
    {
        $columnas = $tabla->columnas->keyBy('clave');
        $this->validar($cambios, $columnas);

        $ids = array_values(array_unique(array_map(fn (array $cambio): int => (int) $cambio['fila'], $cambios)));
        $filas = SeccionFila::query()
            ->where('seccion_tabla_id', $tabla->id)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($filas->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'cambios' => __('Alguna fila ya no existe (quizá alguien la borró). Recarga la hoja.'),
            ]);
        }

        return DB::transaction(function () use ($cambios, $filas, $columnas, $usuario): array {
            foreach ($cambios as $cambio) {
                $this->aplicar($filas[(int) $cambio['fila']], $columnas[$cambio['columna']], $cambio);
            }

            $tocadas = [];

            foreach ($filas as $fila) {
                if ($fila->isDirty()) {
                    $fila->forceFill(['actualizado_por' => $usuario->id])->save();
                }

                $tocadas[$fila->id] = [
                    'datos' => (object) ($fila->datos ?? []),
                    'formato' => (object) ($fila->formato ?? []),
                ];
            }

            return $tocadas;
        });
    }

    /**
     * Valor tal como se guarda: una fórmula queda como texto ("=..."); lo
     * demás se normaliza según el tipo de la columna. null = celda vacía.
     */
    public function normalizarCelda(SeccionColumna $columna, mixed $valor): string|int|float|bool|null
    {
        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if (FormulasHoja::esFormula($valor)) {
            return mb_substr($valor, 0, self::MAX_FORMULA);
        }

        return $this->normalizador->normalizar($valor, $columna->tipo);
    }

    /**
     * @param  array{fila: int, columna: string, valor?: mixed, formato?: array<string, mixed>|null}  $cambio
     */
    private function aplicar(SeccionFila $fila, SeccionColumna $columna, array $cambio): void
    {
        if (array_key_exists('valor', $cambio)) {
            $datos = $fila->datos ?? [];
            $valor = $this->normalizarCelda($columna, $cambio['valor']);

            if ($valor === null) {
                unset($datos[$columna->clave]);
            } else {
                $datos[$columna->clave] = $valor;
            }

            $fila->datos = $datos;
        }

        if (array_key_exists('formato', $cambio)) {
            $formato = $fila->formato ?? [];
            $estilo = EstiloCelda::limpiar($cambio['formato']);

            if ($estilo === null) {
                unset($formato[$columna->clave]);
            } else {
                $formato[$columna->clave] = $estilo;
            }

            $fila->formato = $formato === [] ? null : $formato;
        }
    }

    /**
     * Cada valor debe caber en el tipo de su columna (o ser una fórmula).
     * Una columna Foto no se edita desde la cuadrícula.
     *
     * @param  list<array<string, mixed>>  $cambios
     * @param  Collection<string, SeccionColumna>  $columnas
     */
    private function validar(array $cambios, Collection $columnas): void
    {
        $errores = [];

        foreach ($cambios as $indice => $cambio) {
            $columna = $columnas->get($cambio['columna']);

            if ($columna === null) {
                $errores["cambios.{$indice}.columna"] = __('La columna ya no existe. Recarga la hoja.');

                continue;
            }

            if (! array_key_exists('valor', $cambio)) {
                continue;
            }

            if ($columna->tipo === TipoColumna::Foto) {
                $errores["cambios.{$indice}.valor"] = __('Las fotos se cambian desde «Abrir registro».');

                continue;
            }

            $error = $this->errorDeValor($columna, $cambio['valor']);

            if ($error !== null) {
                $errores["cambios.{$indice}.valor"] = $error;
            }
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }
    }

    private function errorDeValor(SeccionColumna $columna, mixed $valor): ?string
    {
        if (is_string($valor)) {
            $valor = trim($valor);
        }

        if ($valor === null || $valor === '' || FormulasHoja::esFormula($valor)) {
            return null;
        }

        $nombre = $columna->nombre;

        return match ($columna->tipo) {
            TipoColumna::Numero => $this->normalizador->numero($valor) === null ? __('«:col» espera un número.', ['col' => $nombre]) : null,
            TipoColumna::Fecha => $this->normalizador->fecha($valor) === null ? __('«:col» espera una fecha (dd/mm/aaaa).', ['col' => $nombre]) : null,
            TipoColumna::Booleano => $this->normalizador->booleano($valor) === null ? __('«:col» espera Sí o No.', ['col' => $nombre]) : null,
            TipoColumna::Telefono => $this->normalizador->telefono($valor) === null ? __('«:col» espera un teléfono de 10 dígitos.', ['col' => $nombre]) : null,
            TipoColumna::Lista => in_array((string) $valor, $columna->listaOpciones(), true) ? null : __('«:col» solo acepta: :opciones.', ['col' => $nombre, 'opciones' => implode(', ', $columna->listaOpciones())]),
            TipoColumna::Texto, TipoColumna::Foto => null,
        };
    }
}
