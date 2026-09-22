<?php

namespace App\Http\Requests;

use App\Models\EscaleraElectrica;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes editing one escalera eléctrica (módulo
 * "Controles"). The estación is never client-writable — it comes from the
 * route-bound {escalera} itself, so authorization always checks the
 * record's own estación via EscaleraElectricaPolicy::manageFor().
 */
class UpdateEscaleraElectricaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $escalera = $this->route('escalera');

        return $escalera instanceof EscaleraElectrica
            && $this->user()?->can('manageFor', [EscaleraElectrica::class, $escalera->estacion]) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'identificador' => ['nullable', 'string', 'max:255'],
            'modelo' => ['nullable', 'string', 'max:255'],
            'anio_instalacion' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'tipo' => ['nullable', 'string', 'max:255'],
            'operativo' => ['nullable', 'string', 'max:255'],
            'estado_barandales' => ['nullable', 'string', 'max:255'],
            'estado_boton_paro_emergencia' => ['nullable', 'string', 'max:255'],
            'fecha_ultimo_mantenimiento' => ['nullable', 'date'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
