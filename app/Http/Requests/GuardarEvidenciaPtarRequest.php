<?php

namespace App\Http\Requests;

use App\Models\PtarRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Foto de evidencia de la PTAR (imagen). */
class GuardarEvidenciaPtarRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registro = $this->route('registro');

        return $registro instanceof PtarRegistro && $this->user()?->can('update', $registro) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'foto' => ['required', 'file', 'extensions:jpg,jpeg,png', 'mimes:jpg,jpeg,png', 'max:'.(int) config('ptar.evidencia_kb')],
            'pie' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.required' => __('Adjunta una foto (JPG o PNG).'),
            'foto.max' => __('La foto pesa demasiado (máximo :kb MB).', ['kb' => (int) config('ptar.evidencia_kb') / 1024]),
        ];
    }
}
