<?php

namespace App\Http\Requests\Interno;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `GET /interno/contextos/{id}` del puente con Tickets. Ya pasó por
 * VerificarPuente (firma, fecha, nonce e IP); aquí solo se valida el correo de
 * quien abre el formulario en Tickets. No hay sesión ni modelo que autorizar:
 * ContextoFalla solo entrega el contexto a la misma persona que lo creó.
 */
class ContextoFallaRequest extends FormRequest
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
        return [
            'correo' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid'], 422));
    }
}
