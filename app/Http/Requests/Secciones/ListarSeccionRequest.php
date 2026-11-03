<?php

namespace App\Http\Requests\Secciones;

use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
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

    public function authorize(): bool|Response
    {
        return $this->user() ? Gate::forUser($this->user())->inspect('view', $this->route('seccion')) : false;
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
            'tab' => ['nullable', Rule::in(['tabla', 'documentos', 'enlaces', 'cargas'])],
            'doc_orden' => ['nullable', Rule::in(self::ORDENES_DOCUMENTO)],
            'doc_tipo' => ['nullable', 'string', 'max:10', 'alpha_num'],
            // Carpeta del navegador de documentos (ruta relativa a la raíz del origen): sin "..", sin barra inicial.
            'carpeta' => ['nullable', 'string', 'max:500', function (string $atributo, mixed $valor, Closure $falla): void {
                $segmentos = explode('/', (string) $valor);

                if (str_contains((string) $valor, '\\') || preg_match('/[\x00-\x1F]/', (string) $valor) === 1
                    || array_intersect(['', '.', '..'], $segmentos) !== []) {
                    $falla(__('La carpeta no es válida.'));
                }
            }],
            // Id de estación o "sin" (filas sin etiqueta); solo lo respeta la consulta para roles de zona.
            'estacion' => ['nullable', 'regex:/^(\d+|sin)$/'],
        ];
    }

    /**
     * @return array{q: ?string, sort: ?string, direction: string, per_page: int, doc_orden: string, doc_tipo: ?string, carpeta: ?string}
     */
    public function filtros(): array
    {
        return [
            'doc_orden' => $this->validated('doc_orden') ?? self::ORDENES_DOCUMENTO[0],
            'doc_tipo' => $this->validated('doc_tipo'),
            'carpeta' => $this->validated('carpeta'),
            'q' => $this->validated('q'),
            'estacion' => $this->validated('estacion'),
            'sort' => $this->validated('sort'),
            'direction' => $this->validated('direction') ?? 'asc',
            'per_page' => (int) ($this->validated('per_page') ?? config('secciones.filas_por_pagina')[0]),
        ];
    }
}
