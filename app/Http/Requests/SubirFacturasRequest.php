<?php

namespace App\Http\Requests;

use App\Models\Factura;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Carga de uno o varios XML de factura (CFDI) para un mes del fondo
 * rotatorio. Solo Jefe de Zona, Administrador y Edificio.
 */
class SubirFacturasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'xml' => ['required', 'array', 'min:1', 'max:40'],
            'xml.*' => ['file', 'extensions:xml', 'mimes:xml', 'max:2048'],
        ];
    }
}
