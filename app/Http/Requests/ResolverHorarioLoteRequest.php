<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Autorizar o rechazar todo un envío (lote) de solicitudes de una estación. Rechazar exige comentario.
 */
class ResolverHorarioLoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', HorarioPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'comentario' => [$this->routeIs('horarios.lotes.rechazar') ? 'required' : 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['comentario.required' => __('Escribe por qué se rechazan los cambios.')];
    }
}
