<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Autorizar o rechazar la selección de una persona. Rechazar exige comentario. */
class ResolverVacacionPlanRequest extends FormRequest
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
        return ['comentario' => [$this->routeIs('vacaciones.seleccion.rechazar') ? 'required' : 'nullable', 'string', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comentario.required' => __('Escribe qué debe cambiar la persona.')];
    }
}
