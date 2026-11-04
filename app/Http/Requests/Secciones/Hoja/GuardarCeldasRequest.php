<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Support\EstiloCelda;
use Closure;

/**
 * Lote de cambios de celda: [{fila, columna, valor?, formato?}]. El tipo de
 * cada valor contra su columna lo revisa HojaCalculoService (necesita las
 * columnas de la hoja). Solo pasan las claves de estas reglas.
 */
class GuardarCeldasRequest extends EditarHojaRequest
{
    protected function reglasHoja(): array
    {
        return [
            'cambios' => ['required', 'array', 'min:1', 'max:'.config('secciones.hoja_max_celdas_lote')],
            'cambios.*' => ['required', 'array'],
            'cambios.*.fila' => ['required', 'integer', 'min:1'],
            'cambios.*.columna' => ['required', 'string', 'max:64'],
            'cambios.*.valor' => ['nullable', function (string $atributo, mixed $valor, Closure $falla): void {
                if (! is_scalar($valor)) {
                    $falla(__('El valor de la celda no es válido.'));
                } elseif (is_string($valor) && mb_strlen($valor) > 5000) {
                    $falla(__('El texto de una celda no puede pasar de 5000 caracteres.'));
                }
            }],
        ] + EstiloCelda::reglas('cambios.*.formato');
    }

    /**
     * Cambios validados, conservando si cada uno trae "valor" o "formato"
     * (un null explícito significa "vaciar" / "quitar estilo"). El estilo
     * sale de validated(): solo con las claves que tienen regla.
     *
     * @return list<array{fila: int, columna: string, valor?: mixed, formato?: array<string, mixed>|null}>
     */
    public function cambios(): array
    {
        $crudos = $this->input('cambios', []);
        $validados = $this->validated('cambios');

        return array_values(array_map(function (int|string $indice) use ($crudos, $validados): array {
            $cambio = [
                'fila' => (int) $validados[$indice]['fila'],
                'columna' => (string) $validados[$indice]['columna'],
            ];

            if (is_array($crudos[$indice] ?? null) && array_key_exists('valor', $crudos[$indice])) {
                $cambio['valor'] = $validados[$indice]['valor'] ?? null;
            }

            if (is_array($crudos[$indice] ?? null) && array_key_exists('formato', $crudos[$indice])) {
                $cambio['formato'] = $validados[$indice]['formato'] ?? null;
            }

            return $cambio;
        }, array_keys($validados)));
    }
}
