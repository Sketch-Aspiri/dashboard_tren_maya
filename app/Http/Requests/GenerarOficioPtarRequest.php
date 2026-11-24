<?php

namespace App\Http\Requests;

use App\Models\PtarHallazgo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Datos del oficio mensual de las PTAR. */
class GenerarOficioPtarRequest extends FormRequest
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
        return [
            'leyenda' => ['required', 'string', 'max:200'],
            'numero' => ['required', 'string', 'max:80', 'regex:/^[\p{L}\p{N}\/\-\._ ]+$/u'],
            'fecha' => ['required', 'date_format:Y-m-d'],
            'destinatario' => ['required', 'string', 'max:300'],
            'asunto' => ['required', 'string', 'max:1000'],
            'consideraciones' => ['nullable', 'string', 'max:8000'],
            'firmante_cargo' => ['required', 'string', 'max:200'],
            'firmante_nombre' => ['required', 'string', 'max:200'],
        ];
    }
}
