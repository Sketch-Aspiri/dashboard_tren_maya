<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Registro del envío del ANEXO a la Coordinación (solo jefatura): mes y
 * número de oficio, que solo admite letras, números, guion y guion bajo.
 */
class RegistrarEnvioCoordinacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('consolidate', SolicitudInsumo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'oficio' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
