<?php

namespace App\Http\Requests;

use App\Models\VacacionPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambios a las fechas de los trimestres. La Zona los guarda directo; la estación los propone
 * (motivo obligatorio, lo exige el servicio). El alcance por estación lo aplica el servicio.
 */
class CambiarVacacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        return $usuario !== null && ($usuario->can('gestionar', VacacionPeriodo::class) || $usuario->can('solicitar', VacacionPeriodo::class));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tramos' => ['required', 'array', 'max:400'],
            'tramos.*' => ['array'],
            'tramos.*.inicio' => ['nullable', 'date_format:Y-m-d'],
            'tramos.*.fin' => ['nullable', 'date_format:Y-m-d'],
            'motivo' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['tramos.*.inicio.date_format' => __('Fecha de inicio no válida.'), 'tramos.*.fin.date_format' => __('Fecha de término no válida.')];
    }
}
