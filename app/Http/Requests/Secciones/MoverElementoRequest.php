<?php

namespace App\Http\Requests\Secciones;

use App\Support\Reordenador;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subir/bajar un grupo, sección o columna. La autorización la hace cada
 * controlador sobre el modelo concreto (update).
 */
class MoverElementoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'direccion' => ['required', Rule::in([Reordenador::ARRIBA, Reordenador::ABAJO])],
        ];
    }
}
