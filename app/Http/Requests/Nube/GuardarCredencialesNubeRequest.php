<?php

namespace App\Http\Requests\Nube;

use App\Enums\ProveedorNube;
use App\Models\NubeCuenta;
use App\Services\Nube\CredencialesNubeService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GuardarCredencialesNubeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', NubeCuenta::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $proveedor = $this->route('proveedor');
        $guardado = $proveedor instanceof ProveedorNube
            && app(CredencialesNubeService::class)->tieneCredencialGuardada($proveedor);

        return [
            // En blanco = conservar el valor actual; solo son obligatorios la primera vez.
            'client_id' => [Rule::requiredIf(! $guardado), 'nullable', 'string', 'max:255', 'regex:/^\S+$/'],
            'client_secret' => [Rule::requiredIf(! $guardado), 'nullable', 'string', 'max:1000'],
            'tenant' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9.\-]+$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.regex' => __('El Client ID no debe llevar espacios.'),
            'tenant.regex' => __('El tenant solo admite letras, números, puntos y guiones (o "common").'),
        ];
    }
}
