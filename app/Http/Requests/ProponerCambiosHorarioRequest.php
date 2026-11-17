<?php

namespace App\Http\Requests;

use App\Support\Roles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La estación manda los cambios que editó en su vista; la Zona los autoriza (nada cambia en la tabla todavía).
 */
class ProponerCambiosHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole(Roles::ESTACION) === true && $this->user()->estacion_id !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'turnos' => ['required', 'array', 'min:1', 'max:400'],
            'turnos.*.jornada' => ['required', 'string', 'max:255'],
            'turnos.*.comida' => ['nullable', 'string', 'max:100'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'turnos.required' => __('No cambiaste ninguna celda.'),
            'turnos.*.jornada.required' => __('Escribe la jornada de cada celda que cambies (usa DESCANSO si no trabaja).'),
            'motivo.required' => __('Escribe el motivo de los cambios para que Edificio pueda autorizarlos.'),
            'motivo.min' => __('Detalla un poco más el motivo (mínimo 10 caracteres).'),
        ];
    }
}
