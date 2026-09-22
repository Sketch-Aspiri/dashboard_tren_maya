<?php

namespace App\Http\Requests;

use App\Models\Elevador;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes editing one elevador (módulo "Controles").
 * Same shape as UpdateEscaleraElectricaRequest — see that class.
 */
class UpdateElevadorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $elevador = $this->route('elevador');

        return $elevador instanceof Elevador
            && $this->user()?->can('manageFor', [Elevador::class, $elevador->estacion]) === true;
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
            'fecha_ultimo_mantenimiento' => ['nullable', 'date'],
            'estado_puertas_cabina_botoneras' => ['nullable', 'string', 'max:255'],
            'observaciones' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
