<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string (?anio=&mes=) de la lista, el formulario y la descarga de
 * cotizaciones.
 */
class ConsultarCotizacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Cotizacion::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
        ];
    }
}
