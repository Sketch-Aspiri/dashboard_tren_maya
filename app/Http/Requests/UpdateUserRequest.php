<?php

namespace App\Http\Requests;

use App\Models\Estacion;
use Closure;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Validates + authorizes editing a user account from the "Gestión de
 * usuarios" panel. Password is optional here — leaving both password
 * fields blank keeps the current password unchanged; the existing hash is
 * never round-tripped into the form.
 */
class UpdateUserRequest extends FormRequest
{
    private const ESTACION_ROLE = 'Estación';

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('user')) ?? false;
    }

    /**
     * Blank <select>/password inputs submit an empty string, not the
     * absence of the field — normalize to null so "nullable" rules treat
     * "left blank" as "no change requested" rather than as an invalid
     * short/weak password.
     */
    protected function prepareForValidation(): void
    {
        if ($this->input('estacion_id') === '') {
            $this->merge(['estacion_id' => null]);
        }

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
            'role' => ['required', 'string', Rule::in(RoleSeeder::ROLES)],
            'estacion_id' => [
                Rule::requiredIf(fn () => $this->input('role') === self::ESTACION_ROLE),
                'nullable',
                'integer',
                'exists:estaciones,id',
                $this->estacionMustBeOperativaWhenRoleIsEstacion(),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * Same rule as StoreUserRequest — see that class for the rationale.
     */
    private function estacionMustBeOperativaWhenRoleIsEstacion(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if ($this->input('role') !== self::ESTACION_ROLE || $value === null) {
                return;
            }

            $isOperativa = Estacion::whereKey($value)->value('is_operativa');

            if (! $isOperativa) {
                $fail(__('La estación seleccionada no está operativa.'));
            }
        };
    }
}
