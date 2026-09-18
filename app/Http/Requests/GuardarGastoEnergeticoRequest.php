<?php

namespace App\Http\Requests;

use App\Models\ServicioEstacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes a bulk save of one año of "Estadísticas" ->
 * Gasto energético for one estación (bound from the {estacion} route
 * parameter, never from the body): the datos of each servicio (proveedor,
 * contrato, observaciones) and its monthly payments. Same shape as
 * GuardarEstadisticaMensualRequest.
 */
class GuardarGastoEnergeticoRequest extends FormRequest
{
    /** Largest amount that fits the DECIMAL(12,2) column. */
    private const MONTO_MAXIMO = 9999999999.99;

    public function authorize(): bool
    {
        return $this->user()?->can('manage', ServicioEstacion::class) ?? false;
    }

    /**
     * `array:<keys>` restricts the accepted keys, so an unknown tipo de
     * servicio or a mes outside 1..12 is rejected instead of ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'servicios' => ['required', 'array:energia_electrica,agua'],
            'servicios.*.proveedor' => ['nullable', 'string', 'max:255'],
            'servicios.*.contrato' => ['nullable', 'string', 'max:255'],
            'servicios.*.observaciones' => ['nullable', 'string', 'max:5000'],
            'servicios.*.meses' => ['nullable', 'array:'.implode(',', range(1, 12))],
            'servicios.*.meses.*' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONTO_MAXIMO],
        ];
    }
}
