<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La Zona guarda directo los cambios que editó en la vista del horario.
 */
class GuardarHorarioRequest extends FormRequest
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
            'turnos' => ['required', 'array', 'min:1', 'max:400'],
            'turnos.*.jornada' => ['nullable', 'string', 'max:255'],
            'turnos.*.comida' => ['nullable', 'string', 'max:100'],
            'turnos.*.antes' => ['required', 'string', 'max:400'],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['turnos.required' => __('No cambiaste ninguna celda.')];
    }
}
