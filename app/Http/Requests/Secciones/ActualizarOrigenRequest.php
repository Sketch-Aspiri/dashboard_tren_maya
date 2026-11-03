<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Pausar o reanudar un origen y ajustar sus opciones.
 */
class ActualizarOrigenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estado' => ['sometimes', Rule::in(['activo', 'pausado'])],
            'alimenta_tablas' => ['sometimes', 'boolean'],
            'incluir_subcarpetas' => ['sometimes', 'boolean'],
            // Autoriza archivar lo que ya no aparece en la nube cuando el listado llegó vacío.
            'confirmar_baja_masiva' => ['sometimes', 'boolean'],
        ];
    }
}
