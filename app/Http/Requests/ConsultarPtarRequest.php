<?php

namespace App\Http\Requests;

use App\Models\PtarRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConsultarPtarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', PtarRegistro::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['nullable', 'integer', 'between:2020,2100'],
            'mes' => ['nullable', 'integer', 'between:1,12'],
        ];
    }
}
