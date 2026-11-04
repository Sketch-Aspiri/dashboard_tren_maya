<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Models\SeccionColumna;
use Illuminate\Validation\Rule;

class OrdenarHojaRequest extends EditarHojaRequest
{
    protected function reglasHoja(): array
    {
        return [
            'columna' => ['required', 'string', 'max:64', Rule::in($this->tabla()->columnas->pluck('clave')->all())],
            'direccion' => ['required', Rule::in(['asc', 'desc'])],
        ];
    }

    public function columna(): SeccionColumna
    {
        return $this->tabla()->columnas->firstWhere('clave', $this->validated('columna'));
    }
}
