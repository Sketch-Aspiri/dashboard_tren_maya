<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** La persona elige un periodo del catálogo por trimestre (vacío = ninguno). */
class GuardarSeleccionVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('propio', VacacionPlan::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'periodos' => ['present', 'array', 'max:4'],
            'periodos.*' => ['nullable', 'integer'],
        ];
    }
}
