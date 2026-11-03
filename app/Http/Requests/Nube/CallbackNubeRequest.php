<?php

namespace App\Http\Requests\Nube;

use App\Models\NubeCuenta;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query de la redirección del proveedor. Todo es opcional a propósito: un
 * retorno incompleto se rechaza en OAuthNubeService con un mensaje claro.
 */
class CallbackNubeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', NubeCuenta::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:4096'],
            'state' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:100'],
            'error_description' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
