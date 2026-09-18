<?php

namespace App\Http\Requests;

use App\Models\Vacacionista;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes the query-string filters of "Agenda Zona
 * Oriente" -> Rol de vacaciones (q, anio, estacion_id, mes, page).
 */
class ListarRolVacacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Vacacionista::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'estacion_id' => ['nullable', 'integer', 'exists:estaciones,id'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
