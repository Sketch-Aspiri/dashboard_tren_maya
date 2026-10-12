<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Búsqueda, orden y paginación de la tabla/documentos de una sección (y de
 * la exportación a Excel, que respeta la misma búsqueda).
 */
class ListarSeccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(config('secciones.filas_por_pagina'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', Rule::in(['tabla', 'documentos', 'cargas'])],
        ];
    }

    /**
     * @return array{q: ?string, sort: ?string, direction: string, per_page: int}
     */
    public function filtros(): array
    {
        return [
            'q' => $this->validated('q'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction') ?? 'asc',
            'per_page' => (int) ($this->validated('per_page') ?? config('secciones.filas_por_pagina')[0]),
        ];
    }
}
