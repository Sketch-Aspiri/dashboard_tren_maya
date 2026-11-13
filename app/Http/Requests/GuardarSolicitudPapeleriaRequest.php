<?php

namespace App\Http\Requests;

use App\Models\SolicitudPapeleria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Captura de la estación: artículos con unidad, cantidad y comentarios de una
 * SolicitudPapeleria (bound from {solicitud}).
 */
class GuardarSolicitudPapeleriaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof SolicitudPapeleria
            && $this->user()?->can('update', $solicitud) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partidas' => ['required', 'array', 'min:1', 'max:80'],
            'partidas.*.articulo' => ['required', 'string', 'max:255'],
            'partidas.*.unidad' => ['required', 'string', Rule::in(config('papeleria.unidades'))],
            'partidas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'partidas.*.comentarios' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
