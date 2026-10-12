<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Subida de documentos a una sección (arrastrar y soltar o selector).
 * Se valida la extensión declarada Y el tipo real del contenido ("mimes"
 * adivina la extensión a partir de los bytes del archivo): un .pdf que en
 * realidad es un ejecutable se rechaza.
 */
class StoreSeccionArchivoRequest extends FormRequest
{
    private const MAX_ARCHIVOS = 20;

    /**
     * Tipos de contenido aceptados por "mimes". Un CSV se detecta como
     * texto plano ("txt") y un .doc/.xls antiguo a veces como contenedor
     * OLE genérico; por eso se añaden aquí sin aceptar esas extensiones.
     *
     * @var list<string>
     */
    private const MIMES_EXTRA = ['txt', 'cfb', 'zip'];

    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $extensiones = config('secciones.extensiones');

        return [
            'archivos' => ['required', 'array', 'min:1', 'max:'.self::MAX_ARCHIVOS],
            'archivos.*' => [
                'required',
                'file',
                'max:'.config('secciones.max_archivo_kb'),
                'extensions:'.implode(',', $extensiones),
                'mimes:'.implode(',', [...$extensiones, ...self::MIMES_EXTRA]),
            ],
            'seccion_fila_id' => [
                'nullable',
                'integer',
                Rule::exists('seccion_filas', 'id')
                    ->where('seccion_id', $this->route('seccion')->id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'archivos.*.max' => __('Cada archivo puede pesar como máximo :max MB.', ['max' => intdiv((int) config('secciones.max_archivo_kb'), 1024)]),
            'archivos.*.extensions' => __('Tipo de archivo no permitido. Se aceptan: :tipos.', ['tipos' => implode(', ', config('secciones.extensiones'))]),
            'archivos.*.mimes' => __('El contenido del archivo no corresponde a un tipo permitido.'),
        ];
    }
}
