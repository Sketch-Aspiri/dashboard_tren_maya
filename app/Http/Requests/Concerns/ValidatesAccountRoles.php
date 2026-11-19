<?php

namespace App\Http\Requests\Concerns;

use App\Models\Equipo;
use App\Support\Areas;
use App\Support\Roles;
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
        if ($this->input('area') === '' || ! $this->hasRole(Roles::ESTACION)) {
            $this->merge(['area' => null]);
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
                // Cualquier estación del catálogo, incluido "Edificio Zonal Este".
                'exists:estaciones,id',
            ],
            'area' => [
                Rule::requiredIf(fn () => config('equipos.estrictas') && $this->hasRole(Roles::ESTACION)),
                'nullable',
                Rule::in(Areas::claves()),
                // Con áreas estrictas el equipo debe existir en la estación elegida (no todas tienen los mismos).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $estacion = (int) $this->input('estacion_id');
                    if (config('equipos.estrictas') && $estacion > 0 && is_string($value) && ! Equipo::existeEn($value, $estacion)) {
                        $fail(__('Ese equipo no existe en la estación elegida.'));
                    }
                },
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
}
