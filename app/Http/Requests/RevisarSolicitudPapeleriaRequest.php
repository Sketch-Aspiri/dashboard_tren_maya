<?php

namespace App\Http\Requests;

use App\Models\SolicitudPapeleria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Revisión de la Zona: aprobar o devolver (con comentario obligatorio).
 */
class RevisarSolicitudPapeleriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof SolicitudPapeleria
            && $this->user()?->can('review', $solicitud) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accion' => ['required', 'in:devolver,aprobar'],
            'comentario_zona' => ['required_if:accion,devolver', 'nullable', 'string', 'max:5000'],
        ];
    }
}
