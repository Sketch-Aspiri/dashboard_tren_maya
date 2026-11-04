<?php

namespace App\Http\Requests\Secciones\Hoja;

/**
 * Insertar filas vacías antes de una fila ("antes_de" = id) o al final.
 */
class InsertarFilasHojaRequest extends EditarHojaRequest
{
    protected function reglasHoja(): array
    {
        return [
            'antes_de' => ['nullable', 'integer', 'min:1'],
            'cantidad' => ['required', 'integer', 'min:1', 'max:'.config('secciones.hoja_max_filas_lote')],
        ];
    }
}
