<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Foto de perfil: solo JPG/PNG (por contenido real) y tamaño/dimensiones acotados. AvatarService la vuelve a
 * validar y la reprocesa; nunca se guarda el archivo original.
 */
class ActualizarFotoPerfilRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $max = (int) config('ecosistema.avatares.max_dimension');

        return [
            'foto' => ['required', 'file', 'mimes:jpg,jpeg,png', 'mimetypes:image/jpeg,image/png',
                'max:'.(int) config('ecosistema.avatares.max_kb'),
                'dimensions:min_width=32,min_height=32,max_width='.$max.',max_height='.$max],
        ];
    }
}
