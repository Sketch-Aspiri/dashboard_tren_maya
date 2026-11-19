<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConsultarCumpleanosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cumpleanos', Empleado::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mes' => ['nullable', 'integer', 'between:1,12'],
            'estacion' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
