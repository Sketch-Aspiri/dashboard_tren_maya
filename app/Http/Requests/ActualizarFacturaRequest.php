<?php

namespace App\Http\Requests;

use App\Models\Cotizacion;
use App\Models\Factura;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Cotización y estación de una factura, elegidas a mano (la factura llega por
 * la ruta, nunca por el cuerpo). La cotización debe ser del mismo mes.
 */
class ActualizarFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $factura = $this->route('factura');

        return $factura instanceof Factura && $this->user()?->can('manage', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cotizacion_id' => ['nullable', 'integer', 'exists:cotizaciones,id'],
            'estacion_id' => ['nullable', 'integer', 'exists:estaciones,id'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validador) {
            $factura = $this->route('factura');
            $id = $this->input('cotizacion_id');

            if ($id !== null && $id !== '' && ! Cotizacion::query()->whereKey($id)->where('anio', $factura->anio)->where('mes', $factura->mes)->exists()) {
                $validador->errors()->add('cotizacion_id', __('La cotización elegida no es de este mes.'));
            }
        }];
    }
}
