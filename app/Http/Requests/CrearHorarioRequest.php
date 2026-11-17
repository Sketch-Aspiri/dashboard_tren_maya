<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La Zona crea el horario de un mes dentro del sistema (borrador).
 */
class CrearHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', HorarioPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'between:2020,2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
            'estaciones' => ['required', 'array', 'min:1'],
            'estaciones.*' => ['integer', 'exists:estaciones,id'],
            'modo' => ['required', 'in:blanco,copiar'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['estaciones.required' => __('Elige al menos una estación.'), 'estaciones.min' => __('Elige al menos una estación.')];
    }
}
