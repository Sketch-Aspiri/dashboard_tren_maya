<?php

namespace App\Http\Requests\Notificaciones;

use App\Notifications\AvisoDashboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class IndexNotificacionesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['nullable', Rule::in(['todas', 'no-leidas'])],
            'modulo' => ['nullable', Rule::in(AvisoDashboard::MODULOS)],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
