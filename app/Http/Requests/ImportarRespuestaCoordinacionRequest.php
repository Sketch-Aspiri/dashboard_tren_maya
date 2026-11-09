<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Excel devuelto por la Coordinación (solo jefatura): mes y archivo .xlsx de
 * hasta 5 MB.
 */
class ImportarRespuestaCoordinacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('consolidate', SolicitudInsumo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'archivo' => ['required', 'file', 'extensions:xlsx', 'mimes:xlsx', 'max:5120'],
        ];
    }
}
