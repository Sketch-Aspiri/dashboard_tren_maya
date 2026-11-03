<?php

namespace App\Http\Requests\Secciones;

use App\Models\NubeCuenta;
use App\Services\Secciones\OrigenService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Enlace de una carpeta o archivo de la nube que se inspecciona y vincula a
 * una sección, con una cuenta conectada (activa) o, sin cuenta, como enlace
 * público (solo archivos). El enlace nunca llega a la red sin pasar por la
 * lista de sitios permitidos del proveedor.
 */
class VincularOrigenRequest extends FormRequest
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
            'cuenta_id' => ['nullable', 'integer', Rule::exists('nube_cuentas', 'id')->where('estado', 'activa')->whereNull('deleted_at')],
            'enlace' => ['required', 'string', 'max:2000'],
            'alimenta_tablas' => ['nullable', 'boolean'],
            'incluir_subcarpetas' => ['nullable', 'boolean'],
        ];
    }

    public function cuenta(): ?NubeCuenta
    {
        $id = $this->validated('cuenta_id');

        return $id === null ? null : NubeCuenta::query()->activas()->findOrFail($id);
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cuentaId = $this->input('cuenta_id');

            try {
                app(OrigenService::class)->validarEnlace(
                    $cuentaId === null || $cuentaId === '' ? null : NubeCuenta::query()->activas()->find($cuentaId),
                    (string) $this->input('enlace'),
                );
            } catch (ValidationException $e) {
                $validator->errors()->add('enlace', $e->errors()['enlace'][0]);
            }
        }];
    }

    public function messages(): array
    {
        return [
            'enlace.required' => __('Pega el enlace compartido de la carpeta o el archivo.'),
            'enlace.max' => __('El enlace es demasiado largo.'),
            'cuenta_id.exists' => __('Esa cuenta de nube no está conectada o necesita reconectarse.'),
        ];
    }
}
