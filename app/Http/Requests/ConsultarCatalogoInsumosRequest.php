<?php

namespace App\Http\Requests;

use App\Models\CatalogoInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string (?q=&inactivos=) de la pantalla de catálogo.
 */
class ConsultarCatalogoInsumosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', CatalogoInsumo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'inactivos' => ['nullable', 'boolean'],
        ];
    }
}
