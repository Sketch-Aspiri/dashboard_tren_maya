<?php

namespace App\Http\Requests\Secciones;

use App\Enums\TipoColumna;
use App\Models\SeccionColumna;
use App\Models\SeccionTabla;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Etiquetado en bloque de las filas de una tabla con su estación, leyendo el
 * nombre de la estación de una columna de texto o lista de esa misma tabla.
 */
class EtiquetarEstacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('etiquetarEstacion', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'columna' => [
                'required',
                'string',
                Rule::exists('seccion_columnas', 'clave')
                    ->where('seccion_tabla_id', $this->tabla()->id)
                    ->whereIn('tipo', [TipoColumna::Texto->value, TipoColumna::Lista->value]),
            ],
            'sobrescribir' => ['nullable', 'boolean'],
        ];
    }

    public function tabla(): SeccionTabla
    {
        return $this->route('tabla');
    }

    public function columna(): SeccionColumna
    {
        return $this->tabla()->columnas()->where('clave', $this->validated('columna'))->firstOrFail();
    }

    public function sobrescribir(): bool
    {
        return (bool) $this->validated('sobrescribir', false);
    }
}
