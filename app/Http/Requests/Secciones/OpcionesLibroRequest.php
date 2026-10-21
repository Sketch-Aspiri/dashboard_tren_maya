<?php

namespace App\Http\Requests\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Services\Secciones\OpcionesLibro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decisiones de la vista previa de un libro con varias hojas (calcular y
 * aplicar). Aplicar crea secciones, así que además de gestionar los datos
 * exige poder crear secciones. La coherencia con las tablas reales la valida
 * PlanificadorImportacion.
 */
class OpcionesLibroRequest extends FormRequest
{
    public function authorize(): bool
    {
        $usuario = $this->user();

        return $usuario !== null
            && $usuario->can('gestionarDatos', $this->route('seccion'))
            && $usuario->can('create', Seccion::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxColumnas = (int) config('secciones.max_columnas');
        $reemplaza = $this->input('modo') === ModoImportacion::Reemplazar->value;

        return [
            'modo' => ['required', Rule::enum(ModoImportacion::class)],
            'hojas' => ['required', 'array', 'min:1', 'max:'.(int) config('secciones.max_hojas_importacion')],
            'hojas.*.incluir' => ['nullable', 'boolean'],
            'hojas.*.fila_encabezado' => ['required', 'integer', 'min:0'],
            // El mapeo solo es obligatorio en las hojas marcadas para cargar.
            'hojas.*.mapeo' => ['required_if:hojas.*.incluir,1', 'nullable', 'array', 'max:'.$maxColumnas],
            'hojas.*.mapeo.*' => ['required', 'string', 'max:64'],
            'hojas.*.tipos' => ['nullable', 'array', 'max:'.$maxColumnas],
            'hojas.*.tipos.*' => [Rule::enum(TipoColumna::class)],
            'hojas.*.llave' => ['nullable', 'integer', 'min:0'],
            // Reemplazar borra las tablas destino: al aplicar, exige confirmar.
            'confirma_reemplazo' => $reemplaza && $this->routeIs('secciones.importaciones.libro.aplicar')
                ? ['required', 'accepted']
                : ['nullable'],
        ];
    }

    public function messages(): array
    {
        return [
            'confirma_reemplazo.required' => __('Confirma que entiendes que se eliminarán los registros actuales.'),
        ];
    }

    public function opciones(): OpcionesLibro
    {
        return OpcionesLibro::desdeArreglo($this->validated());
    }
}
