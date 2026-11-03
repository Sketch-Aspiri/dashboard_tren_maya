<?php

namespace App\Http\Requests\Concerns;

use App\Models\Estacion;
use App\Support\Roles;
use Closure;
use Database\Seeders\RoleSeeder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Reglas compartidas por StoreUserRequest / UpdateUserRequest para los roles
 * de una cuenta: varios roles permitidos (p. ej. Jefe de Zona + Administrador),
 * salvo Edificio y Estación, que van solos.
 */
trait ValidatesAccountRoles
{
    /**
     * Acepta el campo legado `role` (uno solo) como `roles[]`, y convierte
     * el select de estación vacío en null.
     */
    protected function normalizeAccountInput(): void
    {
        if (! $this->has('roles') && $this->filled('role')) {
            $this->merge(['roles' => [$this->input('role')]]);
        }

        // Sin rol Estación el campo no aplica: un select oculto con un valor
        // viejo no debe producir errores de validación.
        if ($this->input('estacion_id') === '' || ! $this->hasRole(Roles::ESTACION)) {
            $this->merge(['estacion_id' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function roleRules(): array
    {
        return [
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', 'distinct', Rule::in(RoleSeeder::ROLES)],
            'estacion_id' => [
                Rule::requiredIf(fn () => $this->hasRole(Roles::ESTACION)),
                'nullable',
                'integer',
                'exists:estaciones,id',
                $this->estacionMustBeOperativaWhenRoleIsEstacion(),
            ],
        ];
    }

    protected function hasRole(string $role): bool
    {
        return in_array($role, (array) $this->input('roles', []), true);
    }

    /**
     * Edificio / Estación no se combinan con ningún otro rol.
     */
    protected function validateExclusiveRoles(Validator $validator): void
    {
        $roles = (array) $this->input('roles', []);

        if (count($roles) > 1 && array_intersect($roles, Roles::EXCLUSIVOS) !== []) {
            $validator->errors()->add(
                'roles',
                __('Los roles Edificio y Estación no se pueden combinar con otros roles.')
            );
        }
    }

    /**
     * Una cuenta Estación ligada a "Edificio Zonal Este" (no operativa) no
     * tiene sentido: solo se acepta una estación operativa.
     */
    private function estacionMustBeOperativaWhenRoleIsEstacion(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $this->hasRole(Roles::ESTACION) || $value === null) {
                return;
            }

            if (! Estacion::whereKey($value)->value('is_operativa')) {
                $fail(__('La estación seleccionada no está operativa.'));
            }
        };
    }
}
