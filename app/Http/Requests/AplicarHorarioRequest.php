<?php

namespace App\Http\Requests;

use App\Models\HorarioPeriodo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirma la vista previa de un horario oficial. El archivo nunca viene del
 * cliente: solo un token (UUID) que el servicio busca en su propio almacén.
 */
class AplicarHorarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', HorarioPeriodo::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['token' => ['required', 'uuid']];
    }
}
