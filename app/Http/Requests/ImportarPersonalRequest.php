<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Excel de "Base de datos ZONA ORIENTE" arrastrado en RR.HH. > Personal.
 * Acepta tanto "archivo" como "archivos[]" (la zona de arrastre común).
 */
class ImportarPersonalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('importar', Empleado::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return ['archivo' => $this->archivo()];
    }

    public function archivo(): ?UploadedFile
    {
        $archivo = $this->file('archivo') ?? ($this->file('archivos')[0] ?? null);

        return $archivo instanceof UploadedFile ? $archivo : null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'max:'.config('secciones.max_lectura_kb'), 'extensions:xlsx', 'mimes:xlsx,zip'],
        ];
    }

    public function messages(): array
    {
        return [
            'archivo.extensions' => __('Para Personal arrastra el Excel (.xlsx) de la Base de datos Zona Oriente.'),
        ];
    }
}
