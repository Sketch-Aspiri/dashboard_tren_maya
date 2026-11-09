<?php

namespace App\Http\Requests;

use App\Models\SolicitudInsumo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Descarga del consolidado mensual (solo jefatura). El tipo de archivo es
 * una lista cerrada y el número de oficio solo admite letras, números,
 * guion y guion bajo, porque forma parte del nombre del archivo.
 */
class ExportarConsolidadoInsumosRequest extends FormRequest
{
    public const TIPOS = ['anexo', 'listado', 'control'];

    public function authorize(): bool
    {
        return $this->user()?->can('consolidate', SolicitudInsumo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'min:1', 'max:12'],
            'tipo' => ['required', 'in:'.implode(',', self::TIPOS)],
            'oficio' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/'],
        ];
    }
}
