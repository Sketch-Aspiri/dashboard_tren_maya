<?php

namespace App\Http\Requests;

use App\Models\VacacionPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorizar o rechazar todo un envío de la estación. Rechazar exige comentario.
 */
class ResolverVacacionLoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', VacacionPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['comentario' => [$this->routeIs('vacaciones.lotes.rechazar') ? 'required' : 'nullable', 'string', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comentario.required' => __('Escribe por qué se rechazan los cambios.')];
    }
}
