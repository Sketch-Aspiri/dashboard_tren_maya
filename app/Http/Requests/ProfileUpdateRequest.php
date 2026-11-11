<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // El correo es la identidad del inicio de sesión único con Tickets y no se verifica:
            // cambiarlo exige la contraseña actual (y rompe el vínculo, ver User::booted).
            'current_password' => [
                Rule::requiredIf(fn () => mb_strtolower((string) $this->input('email')) !== mb_strtolower((string) $this->user()->email)),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }
}
