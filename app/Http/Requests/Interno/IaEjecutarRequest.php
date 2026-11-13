<?php

namespace App\Http\Requests\Interno;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * `POST /interno/ia/herramientas/{nombre}`: la herramienta, la entrada (la valida cada herramienta, con mensajes para
 * la IA) y el nombre del cliente MCP que la pidió, solo para la bitácora. El cuerpo viaja firmado por el puente.
 */
class IaEjecutarRequest extends FormRequest
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
            'cliente' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9 ._-]+$/'],
            'entrada' => ['nullable', 'array'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json(['error' => 'invalid'], 422));
    }
}
