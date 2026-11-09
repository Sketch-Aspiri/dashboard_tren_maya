<?php

namespace App\Http\Requests;

use App\Models\CatalogoInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta o edición de un insumo del catálogo (el modelo llega por la ruta en
 * la edición, nunca por el cuerpo).
 */
class GuardarCatalogoInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', CatalogoInsumo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $actual = $this->route('catalogoInsumo');

        return [
            'descripcion' => ['required', 'string', 'max:255', Rule::unique('catalogo_insumos', 'descripcion')->ignore($actual?->id)],
            'unidad' => ['required', 'string', 'max:30'],
            'codigo' => ['nullable', 'string', 'max:50'],
            'ultimo_precio' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'partida_presupuestal' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'proveedor' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ];
    }
}
