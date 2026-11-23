<?php

namespace App\Http\Requests;

use App\Models\AguaRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** La Zona fija el programa del mes de una estación. */
class ActualizarProgramaAguaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', AguaRegistro::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'frecuencia' => ['required', Rule::in(config('agua.frecuencias'))],
            'consumo_mensual' => ['required', 'integer', 'between:0,2000'],
            'costo_unitario' => ['required', 'numeric', 'between:0,1000'],
            'garrafones_propios' => ['required', 'integer', 'between:0,500'],
            'garrafones_comodato' => ['required', 'integer', 'between:0,500'],
            'referencia' => ['nullable', 'string', 'max:60'],
            'dia_entrega' => ['nullable', 'string', 'max:60'],
        ];
    }
}
