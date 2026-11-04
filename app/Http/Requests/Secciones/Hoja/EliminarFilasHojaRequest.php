<?php

namespace App\Http\Requests\Secciones\Hoja;

class EliminarFilasHojaRequest extends EditarHojaRequest
{
    protected function reglasHoja(): array
    {
        return [
            'filas' => ['required', 'array', 'min:1', 'max:'.config('secciones.hoja_max_filas_lote')],
            'filas.*' => ['required', 'integer', 'min:1', 'distinct'],
        ];
    }

    /**
     * @return list<int>
     */
    public function filas(): array
    {
        return array_map('intval', array_values($this->validated('filas')));
    }
}
