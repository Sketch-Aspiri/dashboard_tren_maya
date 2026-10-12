<?php

namespace App\Http\Requests\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Services\Secciones\OpcionesImportacion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Decisiones de la vista previa (calcular y aplicar). La coherencia con la
 * tabla real (índices, columnas destino, llave) la valida
 * PlanificadorImportacion contra la sección.
 */
class OpcionesImportacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxColumnas = (int) config('secciones.max_columnas');

        return [
            'tabla' => ['required', 'integer', 'min:0'],
            'fila_encabezado' => ['required', 'integer', 'min:0'],
            'mapeo' => ['required', 'array', 'max:'.$maxColumnas],
            'mapeo.*' => ['required', 'string', 'max:64'],
            'tipos' => ['nullable', 'array', 'max:'.$maxColumnas],
            'tipos.*' => [Rule::enum(TipoColumna::class)],
            'modo' => ['required', Rule::enum(ModoImportacion::class)],
            'llave' => ['nullable', 'integer', 'min:0'],
            // Reemplazar borra toda la tabla: al aplicar, exige la casilla
            // de confirmación (también se exige en pantalla).
            'confirma_reemplazo' => $this->routeIs('secciones.importaciones.aplicar') && $this->input('modo') === ModoImportacion::Reemplazar->value
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

    public function opciones(): OpcionesImportacion
    {
        return OpcionesImportacion::desdeArreglo($this->validated());
    }
}
