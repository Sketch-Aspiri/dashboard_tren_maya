<?php

namespace App\Http\Requests;

use App\Enums\EmpleadoEstatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpleadoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('empleado')) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'no_empleado' => [
                'nullable', 'string', 'max:255',
                Rule::unique('empleados', 'no_empleado')->ignore($this->route('empleado')),
            ],
            'estatus' => ['required', Rule::enum(EmpleadoEstatus::class)],
            'estacion_codigo' => ['nullable', 'string', 'max:255'],
            'estacion_id' => ['nullable', 'integer', Rule::exists('estaciones', 'id')],
            'plaza_actual' => ['nullable', 'string', 'max:255'],
            'nombre_completo' => ['nullable', 'string', 'max:255'],
            'puesto' => ['nullable', 'string', 'max:255'],
            'nivel_plaza' => ['nullable', 'string', 'max:255'],
            'ultimo_grado_estudios' => ['nullable', 'string', 'max:255'],
            'titulo' => ['nullable', 'string', 'max:255'],
            'cedula' => ['nullable', 'string', 'max:255'],
            'fecha_ingreso' => ['nullable', 'date'],
            'telefono' => ['nullable', 'string', 'max:255'],
            'correo' => ['nullable', 'string', 'email', 'max:255'],
            'tipo_sangre' => ['nullable', 'string', 'max:255'],
            'alergias' => ['nullable', 'string'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'lugar_nacimiento' => ['nullable', 'string', 'max:255'],
            'estado_civil' => ['nullable', 'string', 'max:255'],
            'curp' => ['nullable', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:11'],
            'domicilio' => ['nullable', 'string'],
            'contacto_emergencia_nombre' => ['nullable', 'string', 'max:255'],
            'contacto_emergencia_telefono' => ['nullable', 'string', 'max:255'],
            'desempeno' => ['nullable', 'string'],
        ];
    }
}
