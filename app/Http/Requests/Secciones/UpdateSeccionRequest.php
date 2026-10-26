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
        // "ubicacion" = "g-<grupo>" (raíz de un grupo) o "s-<sección>" (dentro de otra sección).
        if (is_string($this->input('ubicacion')) && preg_match('/^([gs])-(\d+)$/', $this->input('ubicacion'), $partes)) {
            $this->merge($partes[1] === 'g'
                ? ['menu_grupo_id' => (int) $partes[2], 'parent_id' => null]
                : ['parent_id' => (int) $partes[2]]);
        }

        foreach (['is_visible', 'solo_documentos'] as $campo) {
            if ($this->has($campo)) {
                $this->merge([$campo => $this->boolean($campo)]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'menu_grupo_id' => ['sometimes', 'required', 'integer', Rule::exists('menu_grupos', 'id')],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('secciones', 'id')->whereNull('deleted_at')],
            'nombre' => ['sometimes', 'required', 'string', 'max:160'],
            'descripcion' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_visible' => ['sometimes', 'boolean'],
            'solo_documentos' => ['sometimes', 'boolean'],
        ];
    }
}
