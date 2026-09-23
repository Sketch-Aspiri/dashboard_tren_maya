<?php

namespace App\Http\Requests;

use App\Models\EstatusViaAnden;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates and authorizes editing one vía/andén (módulo "Controles").
 * Same shape as UpdateElevadorRequest — see that class. La estación y el
 * número de vía identifican el registro y no se editan.
 */
class UpdateEstatusViaAndenRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registro = $this->route('estatusViaAnden');

        return $registro instanceof EstatusViaAnden
            && $this->user()?->can('manageFor', [EstatusViaAnden::class, $registro->estacion]) === true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'anden' => ['nullable', 'string', 'max:10'],
            'estatus_anden' => ['nullable', 'string', 'max:255'],
            'estatus_via' => ['nullable', 'string', 'max:255'],
            'senaletica' => ['nullable', 'string', 'max:255'],
            'teleindicadores' => ['nullable', 'string', 'max:255'],
            'pruebas_galibo' => ['nullable', 'string', 'max:255'],
            'riesgos_obstaculos' => ['nullable', 'string', 'max:5000'],
            'comentarios' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
