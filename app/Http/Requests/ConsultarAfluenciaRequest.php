<?php

namespace App\Http\Requests;

use App\Models\AfluenciaDiaria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConsultarAfluenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AfluenciaDiaria::class) === true;
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
