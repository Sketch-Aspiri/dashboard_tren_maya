<?php

namespace App\Http\Requests;

use App\Models\HorarioSolicitud;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorizar o rechazar una solicitud de horario (route: {solicitud}). Rechazar
 * exige comentario para que la estación sepa por qué.
 */
class ResolverHorarioSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof HorarioSolicitud && $this->user()?->can('resolver', $solicitud) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rechaza = $this->routeIs('horarios.solicitudes.rechazar');

        return [
            'comentario' => [$rechaza ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comentario.required' => __('Escribe por qué se rechaza el cambio.')];
    }
}
