<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Filtros y orden del listado de usuarios (la autorización la hace el controlador con la Policy). */
class IndexUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'orden' => ['nullable', 'string', 'in:nombre,rol,estacion,area'],
            'dir' => ['nullable', 'string', 'in:asc,desc'],
            'rol' => ['nullable', 'string', 'max:40'],
            'estacion' => ['nullable', 'string', 'max:10'],
            'area' => ['nullable', 'string', 'max:32'],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
