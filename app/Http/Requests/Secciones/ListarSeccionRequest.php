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
    use ConTablaDeSeccion;

    /** Órdenes de la pestaña Documentos; el primero es el predeterminado. */
    public const ORDENES_DOCUMENTO = [
        'recientes', 'antiguos', 'nombre_asc', 'nombre_desc',
        'numero_asc', 'numero_desc', 'tamano_desc', 'tamano_asc',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->reglaTabla() + [
            'q' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'max:64'],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', Rule::in(config('secciones.filas_por_pagina'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'tab' => ['nullable', Rule::in(['tabla', 'documentos', 'cargas'])],
            'doc_orden' => ['nullable', Rule::in(self::ORDENES_DOCUMENTO)],
            'doc_tipo' => ['nullable', 'string', 'max:10', 'alpha_num'],
        ];
    }

    /**
     * @return array{q: ?string, sort: ?string, direction: string, per_page: int, doc_orden: string, doc_tipo: ?string}
     */
    public function filtros(): array
    {
        return [
            'doc_orden' => $this->validated('doc_orden') ?? self::ORDENES_DOCUMENTO[0],
            'doc_tipo' => $this->validated('doc_tipo'),
            'q' => $this->validated('q'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction') ?? 'asc',
            'per_page' => (int) ($this->validated('per_page') ?? config('secciones.filas_por_pagina')[0]),
        ];
    }
}
