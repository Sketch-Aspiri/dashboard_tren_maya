<?php

namespace App\Http\Requests\Interno;

use App\Services\Ecosistema\PortalAprovisionamiento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cuenta aprobada en el portal. La autorización es la firma del puente (VerificarPuentePortal): aquí solo se
 * exige que la petición la haya autenticado.
 */
class PortalCuentaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('sistema_puente') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'portal_user_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[^\p{C}]+$/u'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'regex:/^[^A-Z]+$/D'],
            // Hash bcrypt del portal (no la contraseña): la persona entra aquí con la misma contraseña.
            'password_hash' => ['required', 'string', 'regex:/^\$2y\$\d{2}\$[A-Za-z0-9.\/]{53}$/D'],
            'role' => ['required', 'string', Rule::in(array_keys(PortalAprovisionamiento::ROLES))],
            'estacion_id' => ['nullable', 'integer', 'exists:estaciones,id'],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('role') === 'estacion' && ! $this->filled('estacion_id')) {
                $validator->errors()->add('estacion_id', 'required');
            }
        }];
    }
}
