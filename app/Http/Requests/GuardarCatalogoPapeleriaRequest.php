<?php

namespace App\Http\Requests;

use App\Models\CatalogoPapeleria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de un artículo del catálogo de papelería (solo jefatura).
 */
class GuardarCatalogoPapeleriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', CatalogoPapeleria::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actual = $this->route('catalogoPapeleria');

        return [
            'descripcion' => ['required', 'string', 'max:255', Rule::unique('catalogo_papeleria', 'descripcion')->ignore($actual?->id)],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}
