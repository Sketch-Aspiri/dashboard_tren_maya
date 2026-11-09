<?php

namespace App\Http\Requests;

use App\Models\Proveedor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de un proveedor o cambio de su tasa de IVA (solo jefatura). El nombre
 * solo se captura al crear: las partidas se relacionan con el proveedor por
 * nombre, así que no se renombra.
 */
class GuardarProveedorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Proveedor::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reglas = [
            'iva' => ['nullable', 'integer', Rule::in(config('insumos.tasas_iva'))],
        ];

        if ($this->route('proveedor') === null) {
            $reglas['nombre'] = ['required', 'string', 'max:150', Rule::unique('proveedores', 'nombre')];
        }

        return $reglas;
    }
}
