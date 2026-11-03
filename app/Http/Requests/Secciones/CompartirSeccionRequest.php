<?php

namespace App\Http\Requests\Secciones;

use App\Enums\NivelAccesoEstacion;
use App\Models\Estacion;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Permisos de las estaciones sobre una sección completa (solo Jefe de Zona /
 * Administrador): nivel para todas ("todas" = ver|editar, vacío = no) y
 * nivel por estación ("estaciones[<id>]" = ver|editar, vacío = sin acceso).
 * Cualquier estación del catálogo, incluido el Edificio Zonal.
 */
class CompartirSeccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('aprobarEstaciones', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // "present": un formulario incompleto (sin el campo) no revoca permisos en silencio.
            'todas' => ['present', 'nullable', Rule::in(NivelAccesoEstacion::valores())],
            'estaciones' => ['present', 'array', $this->clavesSonEstaciones()],
            'estaciones.*' => ['nullable', Rule::in(NivelAccesoEstacion::valores())],
        ];
    }

    public function todas(): ?NivelAccesoEstacion
    {
        return NivelAccesoEstacion::tryFrom((string) $this->validated('todas'));
    }

    /**
     * @return array<int, NivelAccesoEstacion> estación => nivel (sin las "sin acceso")
     */
    public function estaciones(): array
    {
        $niveles = [];

        foreach ($this->validated('estaciones', []) ?? [] as $id => $valor) {
            $nivel = NivelAccesoEstacion::tryFrom((string) $valor);

            if ($nivel !== null) {
                $niveles[(int) $id] = $nivel;
            }
        }

        return $niveles;
    }

    /**
     * Las claves de "estaciones" deben ser ids de estaciones existentes.
     */
    private function clavesSonEstaciones(): Closure
    {
        return function (string $atributo, mixed $valor, Closure $falla): void {
            $ids = array_keys((array) $valor);

            foreach ($ids as $id) {
                if (! is_int($id) && ! ctype_digit((string) $id)) {
                    $falla(__('Estación no válida.'));

                    return;
                }
            }

            if (Estacion::query()->whereIn('id', array_map('intval', $ids))->count() !== count($ids)) {
                $falla(__('Estación no válida.'));
            }
        };
    }
}
