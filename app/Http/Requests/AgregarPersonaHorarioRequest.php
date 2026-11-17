<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La Zona agrega una persona al horario: del directorio (empleado_id) o escribiendo sus datos (route: {periodo}).
 */
class AgregarPersonaHorarioRequest extends FormRequest
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
            'empleado_id' => ['nullable', 'integer', 'exists:empleados,id'],
            'estacion_id' => ['required', 'integer', 'exists:estaciones,id'],
            'no_empleado' => ['required_without:empleado_id', 'nullable', 'string', 'max:30'],
            'nombre' => ['required_without:empleado_id', 'nullable', 'string', 'max:255'],
            'puesto' => ['nullable', 'string', 'max:255'],
        ];
    }
}
