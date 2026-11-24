<?php

namespace App\Http\Requests;

use App\Models\PtarHallazgo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConsultarHallazgosPtarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PtarHallazgo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::in(['activos', 'todos', PtarHallazgo::ABIERTO, PtarHallazgo::EN_PROCESO, PtarHallazgo::CERRADO])],
            'planta' => ['nullable', 'integer', 'min:1'],
            'tipo' => ['nullable', Rule::in([PtarHallazgo::PROBLEMATICA, PtarHallazgo::PROPUESTA])],
        ];
    }
}
