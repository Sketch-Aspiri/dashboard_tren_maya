<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Captura de la estación: cabecera y partidas (cantidad, unidad, descripción
 * y justificación) de una SolicitudInsumo (bound from {solicitud}). Los
 * precios, el código y la partida presupuestal NO se aceptan aquí: los
 * completa la Zona (RevisarSolicitudInsumoRequest).
 */
class GuardarSolicitudInsumoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $solicitud = $this->route('solicitud');

        return $solicitud instanceof SolicitudInsumo
            && $this->user()?->can('update', $solicitud) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo_servicio' => ['required', 'in:1er escalón,2do escalón'],
            'lugar_trabajo' => ['nullable', 'string', 'max:255'],
            'justificacion_general' => ['nullable', 'string', 'max:5000'],
            'solicitante_nombre' => ['nullable', 'string', 'max:255'],
            'solicitante_puesto' => ['nullable', 'string', 'max:255'],
            'partidas' => ['required', 'array', 'min:1', 'max:60'],
            'partidas.*.cantidad' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'partidas.*.unidad' => ['required', 'string', 'max:30'],
            'partidas.*.descripcion' => ['required', 'string', 'max:255'],
            'partidas.*.justificacion' => ['nullable', 'string', 'max:2000'],
            'partidas.*.catalogo_insumo_id' => ['nullable', 'integer', 'exists:catalogo_insumos,id'],
        ];
    }
}
