<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PDF de una cotización que se manda a la IA para proponer los datos del
 * formulario, más el mes en que se capturará (para sugerir partidas).
 */
class LeerCotizacionIaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Cotizacion::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'archivo' => ['required', 'file', 'extensions:pdf', 'mimes:pdf', 'max:10240'],
        ];
    }
}
