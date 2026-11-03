<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aprobación de una sección para estaciones (solo Jefe de Zona /
 * Administrador): "todas" o una lista de estaciones operativas.
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
            'todas' => ['nullable', 'boolean'],
            'estaciones' => ['nullable', 'array'],
            'estaciones.*' => ['integer', 'distinct', Rule::exists('estaciones', 'id')->where('is_operativa', true)],
        ];
    }

    public function todas(): bool
    {
        return (bool) $this->validated('todas', false);
    }

    /**
     * @return list<int>
     */
    public function estaciones(): array
    {
        return array_map('intval', $this->validated('estaciones', []) ?? []);
    }
}
