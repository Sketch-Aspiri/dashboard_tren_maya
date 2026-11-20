<?php

namespace App\Http\Requests\Interno;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil única por el puente con el portal. La autorización es la firma del puente (VerificarPuentePortal);
 * la imagen la vuelve a validar y reprocesa AvatarService.
 */
class PortalFotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('sistema_puente') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $correo = ['required', 'string', 'email:rfc', 'max:255'];

        return $this->isMethod('PUT')
            ? ['email' => $correo, 'imagen' => ['required', 'string', 'max:2000000']]
            : ['email' => $correo];
    }
}
