<?php

namespace App\Http\Requests\Secciones;

use Illuminate\Foundation\Http\FormRequest;

class VistaPreviaArchivoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $seccion = $this->route('seccion');

        return $seccion !== null && ($this->user()?->can('view', $seccion) ?? false);
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
