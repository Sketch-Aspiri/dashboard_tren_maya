<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Tope de personas de vacaciones a la vez por estación (estacion_id => máximo). */
class ActualizarTopesVacacionRequest extends FormRequest
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
        return [
            'topes' => ['required', 'array', 'max:50'],
            'topes.*' => ['required', 'integer', 'between:1,20'],
        ];
    }
}
