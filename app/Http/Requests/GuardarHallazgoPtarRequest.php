<?php

namespace App\Http\Requests;

use App\Models\PtarHallazgo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o edición de un hallazgo de PTAR (problemática o propuesta). */
class GuardarHallazgoPtarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', PtarHallazgo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $esAlta = $this->route('hallazgo') === null;

        return [
            'planta_id' => [$esAlta ? 'required' : 'prohibited', 'integer', Rule::exists('ptar_plantas', 'id')],
            'tipo' => ['required', Rule::in([PtarHallazgo::PROBLEMATICA, PtarHallazgo::PROPUESTA])],
            'equipo' => ['nullable', Rule::in(array_keys(config('ptar.equipos')))],
            'descripcion' => ['required', 'string', 'max:2000'],
            'propuesta' => ['nullable', 'string', 'max:2000'],
            'prioridad' => ['required', Rule::in(['alta', 'media', 'baja'])],
            'estado' => ['required', Rule::in([PtarHallazgo::ABIERTO, PtarHallazgo::EN_PROCESO, PtarHallazgo::CERRADO])],
            'fecha_compromiso' => ['nullable', 'date_format:Y-m-d'],
            'fecha_cierre' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'tarjeta' => ['nullable', 'string', 'max:60'],
        ];
    }
}
