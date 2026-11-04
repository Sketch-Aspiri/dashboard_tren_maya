<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Services\Secciones\Hoja\DisenoHojaService;
use Illuminate\Validation\Rule;

/**
 * Celdas combinadas y rangos con formato de tabla de la hoja, completos
 * (reemplazan a los anteriores). Posiciones base 0.
 */
class GuardarDisenoHojaRequest extends EditarHojaRequest
{
    protected function reglasHoja(): array
    {
        $reglas = [
            'combinaciones' => ['present', 'array', 'max:'.DisenoHojaService::MAX_RANGOS],
            'tablas' => ['present', 'array', 'max:'.DisenoHojaService::MAX_RANGOS],
            'tablas.*.estilo' => ['required', Rule::in(DisenoHojaService::estilosTabla())],
            'tablas.*.encabezado' => ['nullable', 'boolean'],
            'tablas.*.bandas' => ['nullable', 'boolean'],
        ];

        foreach (['combinaciones', 'tablas'] as $grupo) {
            foreach (['f1', 'c1', 'f2', 'c2'] as $clave) {
                $reglas["{$grupo}.*.{$clave}"] = ['required', 'integer', 'min:0', 'max:1000000'];
            }
        }

        return $reglas;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function combinaciones(): array
    {
        return array_values($this->validated('combinaciones', []));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function tablas(): array
    {
        return array_values($this->validated('tablas', []));
    }
}
