<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConsultarCalendarioVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', VacacionPlan::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'q' => ['nullable', 'string', 'max:100'],
            'estacion' => ['nullable', 'integer', 'exists:estaciones,id'],
            'estado' => ['nullable', 'in:todas,autorizada,enviada'],
        ];
    }
}
