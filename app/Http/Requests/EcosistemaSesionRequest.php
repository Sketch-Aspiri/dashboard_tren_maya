<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Parámetros de las rutas de sesión única del ecosistema. `para` solo puede
 * ser otro sistema conocido (nunca una URL: las direcciones salen de
 * config/ecosistema.php), y `codigo` es un texto acotado que después se
 * verifica con su firma.
 */
class EcosistemaSesionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $otros = array_values(array_diff(array_keys((array) config('ecosistema.urls')), [config('ecosistema.sistema')]));

        return [
            'para' => [Rule::requiredIf($this->routeIs('ecosistema.sso')), 'nullable', 'string', Rule::in($otros)],
            'codigo' => ['nullable', 'string', 'max:2048'],
            'estado' => ['nullable', 'string', 'alpha_num', 'size:40'],
            'sin_sesion' => ['nullable'],
        ];
    }
}
