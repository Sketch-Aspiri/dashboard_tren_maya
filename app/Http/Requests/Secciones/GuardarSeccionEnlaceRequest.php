<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición de un enlace de sección. Solo http(s): nunca javascript:,
 * data: ni otros esquemas que se ejecutarían al hacer clic.
 */
class GuardarSeccionEnlaceRequest extends FormRequest
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
        return [
            'titulo' => ['required', 'string', 'max:160'],
            'url' => ['required', 'string', 'max:2000', 'url:http,https'],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'url.url' => __('Escribe una dirección que empiece con http:// o https://.'),
        ];
    }
}
