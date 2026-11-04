<?php

namespace App\Http\Requests\Secciones\Hoja;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Nombre de una hoja nueva, renombrada o duplicada (estructura: update).
 */
class GuardarHojaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('seccion')) ?? false;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:160'],
        ];
    }

    public function nombre(): string
    {
        return trim($this->validated('nombre'));
    }
}
