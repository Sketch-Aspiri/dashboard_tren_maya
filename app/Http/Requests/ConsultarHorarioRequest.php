<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Filtros de la tabla del horario. El alcance por estación NO viene de aquí:
 * una cuenta de estación se limita a la suya en el controlador.
 */
class ConsultarHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', HorarioPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'between:2020,2100'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'estacion' => ['nullable', 'integer', 'exists:estaciones,id'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
            'editar' => ['nullable', 'boolean'],
        ];
    }
}
