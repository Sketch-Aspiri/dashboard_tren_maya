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
            'menu_grupo_id' => ['required', 'integer', Rule::exists('menu_grupos', 'id')],
            'nombre' => ['required', 'string', 'max:160'],
            'descripcion' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
