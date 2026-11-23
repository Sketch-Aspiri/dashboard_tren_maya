<?php

namespace App\Http\Requests;

use App\Models\AguaRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Revisión de la Zona: aprobar o devolver (con comentario obligatorio). */
class RevisarAguaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registro = $this->route('registro');

        return $registro instanceof AguaRegistro && $this->user()?->can('review', $registro) === true;
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
