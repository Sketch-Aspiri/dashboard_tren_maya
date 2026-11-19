<?php

namespace App\Http\Requests;

use App\Models\VacacionPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirma o descarta la vista previa. El archivo nunca viene del cliente: solo un token (UUID).
 */
class AplicarVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', VacacionPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'uuid']];
    }
}
