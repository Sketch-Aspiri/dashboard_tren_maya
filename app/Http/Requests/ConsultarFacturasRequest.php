<?php

namespace App\Http\Requests;

use App\Models\Factura;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string (?anio=&mes=&tipo=) de la lista de facturas y de la descarga
 * de COMPRA DE INSUMOS / REPORTE_COMPROBACION. El tipo es una lista cerrada.
 */
class ConsultarFacturasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'tipo' => ['nullable', 'in:compra,reporte'],
        ];
    }
}
