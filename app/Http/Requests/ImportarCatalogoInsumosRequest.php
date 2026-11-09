<?php

namespace App\Http\Requests;

use App\Models\CatalogoInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Archivo ANEXO (.xlsx) del que se cargan los insumos al catálogo.
 */
class ImportarCatalogoInsumosRequest extends FormRequest
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
        return [
            'archivo' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
