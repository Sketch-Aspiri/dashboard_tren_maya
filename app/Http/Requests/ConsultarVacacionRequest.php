<?php

namespace App\Http\Requests;

use App\Models\VacacionPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConsultarVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', VacacionPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
            'estacion' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'editar' => ['nullable', 'boolean'],
        ];
    }
}
