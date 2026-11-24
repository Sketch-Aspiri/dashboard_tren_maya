<?php

namespace App\Http\Requests;

use App\Models\PtarEquipo;
use App\Models\PtarRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Captura mensual de la PTAR: modo, equipos, actividades de mantenimiento y observaciones. */
class GuardarPtarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registro = $this->route('registro');

        return $registro instanceof PtarRegistro && $this->user()?->can('update', $registro) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'modo' => ['required', Rule::in([PtarRegistro::MODO_AUTOMATICO, PtarRegistro::MODO_MANUAL, PtarRegistro::MODO_FUERA_SERVICIO, PtarRegistro::MODO_SIN_DATO])],
            'modo_detalle' => ['nullable', 'string', 'max:255'],
            'operador' => ['nullable', 'string', 'max:160'],
            'observaciones_equipos' => ['nullable', 'string', 'max:5000'],
            'observaciones_generales' => ['nullable', 'string', 'max:5000'],
            'equipos' => ['nullable', 'array'],
            'equipos.*.estatus' => ['required', Rule::in(array_keys(PtarEquipo::ETIQUETAS))],
            'equipos.*.observaciones' => ['nullable', 'string', 'max:2000'],
            'actividades' => ['nullable', 'array'],
            'actividades.*.ultima_fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'actividades.*.nota' => ['nullable', 'string', 'max:255'],
        ];
    }
}
