<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class VistaPreviaArchivoRequest extends FormRequest
{
    public function authorize(): bool|Response
    {
        $seccion = $this->route('seccion');

        return $seccion !== null && $this->user() ? Gate::forUser($this->user())->inspect('view', $seccion) : false;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'hoja' => ['nullable', 'integer', 'min:0', 'max:200'],
        ];
    }
}
