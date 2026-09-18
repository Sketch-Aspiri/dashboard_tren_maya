<?php

namespace App\Http\Requests;

use App\Models\ServicioEstacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes the query string of "Estadísticas" -> Gasto
 * energético (`anio`).
 */
class ConsultarGastoEnergeticoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ServicioEstacion::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
