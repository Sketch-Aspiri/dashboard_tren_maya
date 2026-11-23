<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** La Zona corrige los días que le corresponden a una persona (antigüedad, caso especial). */
class AjustarDiasVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', VacacionPlan::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['dias' => ['required', 'integer', 'in:0,5,10,15,20,25,30']];
    }
}
