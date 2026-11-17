<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sube el horario oficial (Excel). Solo la Zona.
 */
class ImportarHorarioRequest extends FormRequest
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
            'archivo' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => __('Elige el Excel del horario oficial.'),
            'archivo.mimes' => __('El horario oficial debe ser un archivo Excel (.xlsx).'),
            'archivo.extensions' => __('El horario oficial debe ser un archivo Excel (.xlsx).'),
            'archivo.max' => __('El archivo no puede pesar más de 10 MB.'),
        ];
    }
}
