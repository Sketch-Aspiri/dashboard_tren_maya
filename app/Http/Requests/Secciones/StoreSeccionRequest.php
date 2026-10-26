<?php

namespace App\Http\Requests\Secciones;

use App\Models\Seccion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSeccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Seccion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'menu_grupo_id' => ['required_without:parent_id', 'nullable', 'integer', Rule::exists('menu_grupos', 'id')],
            'parent_id' => ['nullable', 'integer', Rule::exists('secciones', 'id')->whereNull('deleted_at')],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
