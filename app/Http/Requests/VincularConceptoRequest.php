<?php

namespace App\Http\Requests;

use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Services\CotizacionService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Partida con la que se vincula a mano un concepto de factura (el concepto
 * llega por la ruta). Debe ser una partida vigente del mismo mes.
 */
class VincularConceptoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('concepto') instanceof FacturaConcepto && $this->user()?->can('manage', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'partida_id' => ['nullable', 'integer', 'exists:solicitud_insumo_partidas,id'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validador) {
            $id = $this->input('partida_id');

            if ($id === null || $id === '') {
                return;
            }

            $factura = $this->route('concepto')->factura;
            $validas = app(CotizacionService::class)->partidasDelMes($factura->anio, $factura->mes)->pluck('id');

            if (! $validas->contains((int) $id)) {
                $validador->errors()->add('partida_id', __('La partida elegida no es una partida aprobada de este mes.'));
            }
        }];
    }
}
