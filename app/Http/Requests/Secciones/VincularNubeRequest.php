<?php

namespace App\Http\Requests\Secciones;

use App\Services\Secciones\EnlaceNubeResolver;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Enlace compartido (Google Drive, OneDrive/SharePoint, Dropbox) al que se
 * vincula un documento para mantenerlo actualizado.
 */
class VincularNubeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enlace' => ['required', 'string', 'max:2000'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('enlace')) {
                return;
            }

            try {
                app(EnlaceNubeResolver::class)->resolver((string) $this->input('enlace'));
            } catch (ValidationException $e) {
                $validator->errors()->add('enlace', $e->errors()['enlace'][0]);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'enlace.required' => __('Pega el enlace compartido del archivo.'),
            'enlace.max' => __('El enlace es demasiado largo.'),
        ];
    }
}
