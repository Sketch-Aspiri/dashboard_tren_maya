<?php

namespace App\Http\Requests\Interno;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `GET /interno/ia/herramientas` del puente con Tickets: sin datos de entrada. Ya pasó por VerificarPuente (firma,
 * fecha, nonce e IP) y EnsureIaHabilitada; aquí solo se exige que lo haya firmado un sistema del ecosistema.
 */
class IaHerramientasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return is_string($this->attributes->get('sistema_puente'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }
}
