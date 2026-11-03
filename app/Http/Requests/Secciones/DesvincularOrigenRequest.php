<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `conservar` = true deja los documentos como normales; false los archiva.
 */
class DesvincularOrigenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['conservar' => ['sometimes', 'boolean']];
    }

    public function conservar(): bool
    {
        return $this->boolean('conservar', true);
    }
}
