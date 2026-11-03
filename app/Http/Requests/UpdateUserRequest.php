<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAccountRoles;
use App\Models\User;
use App\Support\Roles;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Validates + authorizes editing a user account from the "Gestión de
 * usuarios" panel. Password is optional here — leaving both password
 * fields blank keeps the current password unchanged; the existing hash is
 * never round-tripped into the form.
 */
class UpdateUserRequest extends FormRequest
{
    use ValidatesAccountRoles;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Un campo de contraseña vacío significa "no cambiar".
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeAccountInput();

        if ($this->input('password') === '') {
            $this->merge(['password' => null, 'password_confirmation' => null]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->ignore($this->route('user')),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ] + $this->roleRules();
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateExclusiveRoles($validator),
            fn (Validator $validator) => $this->validateAdministradorIsKept($validator),
        ];
    }

    /**
     * Nadie puede quitarse a sí mismo el rol Administrador, y el último
     * Administrador del sistema no puede perderlo (evita quedarse sin quien
     * gestione cuentas).
     */
    private function validateAdministradorIsKept(Validator $validator): void
    {
        /** @var User $target */
        $target = $this->route('user');

        if (! $target->hasRole(Roles::ADMINISTRADOR) || $this->hasRole(Roles::ADMINISTRADOR)) {
            return;
        }

        $esElUltimo = User::role(Roles::ADMINISTRADOR)->count() <= 1;

        if ($target->is($this->user()) || $esElUltimo) {
            $validator->errors()->add('roles', __('No se puede quitar el rol Administrador a esta cuenta.'));
        }
    }
}
