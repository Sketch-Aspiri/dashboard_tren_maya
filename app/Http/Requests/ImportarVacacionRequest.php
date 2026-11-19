<?php

namespace App\Http\Requests;

use App\Models\VacacionPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sube el rol de vacaciones (Excel). Solo la Zona.
 */
class ImportarVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', VacacionPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['archivo' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:10240']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'archivo.required' => __('Elige el Excel del rol de vacaciones.'),
            'archivo.mimes' => __('El rol de vacaciones debe ser un archivo Excel (.xlsx).'),
            'archivo.extensions' => __('El rol de vacaciones debe ser un archivo Excel (.xlsx).'),
            'archivo.max' => __('El archivo no puede pesar más de 10 MB.'),
        ];
    }
}
