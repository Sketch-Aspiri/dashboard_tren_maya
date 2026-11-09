<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string (?anio=&mes=) de la lista, el tablero y el consolidado de
 * solicitudes de insumos.
 */
class ConsultarSolicitudesInsumosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', SolicitudInsumo::class) === true;
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
