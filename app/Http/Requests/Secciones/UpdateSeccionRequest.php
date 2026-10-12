<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSeccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('seccion')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('is_visible')) {
            $this->merge(['is_visible' => $this->boolean('is_visible')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'menu_grupo_id' => ['sometimes', 'required', 'integer', Rule::exists('menu_grupos', 'id')],
            'nombre' => ['sometimes', 'required', 'string', 'max:160'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
    }
}
