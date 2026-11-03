<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambia la estación de un documento (solo roles de zona).
 */
class EtiquetarArchivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('etiquetarEstacion', $this->route('seccion')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('estacion_id') === '') {
            $this->merge(['estacion_id' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estacion_id' => ['nullable', 'integer', 'exists:estaciones,id'],
        ];
    }
}
