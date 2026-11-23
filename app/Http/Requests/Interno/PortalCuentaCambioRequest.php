<?php

namespace App\Http\Requests\Interno;

use App\Models\Equipo;
use App\Services\Ecosistema\PortalAprovisionamiento;
use App\Support\Areas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Cambios del portal sobre una cuenta ya emparejada (estado y/o rol, estacion y area). La autorizacion es la
 * firma del puente (VerificarPuentePortal).
 */
class PortalCuentaCambioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->attributes->get('sistema_puente') === 'portal';
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'estado' => ['nullable', 'string', Rule::in(['activo', 'inactivo'])],
            'role' => ['nullable', 'string', Rule::in(array_keys(PortalAprovisionamiento::ROLES))],
            'estacion_id' => ['nullable', 'integer', 'exists:estaciones,id'],
            'area' => ['nullable', 'string', Rule::in(Areas::claves())],
        ];
    }

    /** @return array<int, \Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->filled('estado') && ! $this->filled('role')) {
                $validator->errors()->add('estado', 'required');

                return;
            }

            if ($this->input('role') !== 'estacion') {
                return;
            }

            if (! $this->filled('estacion_id')) {
                $validator->errors()->add('estacion_id', 'required');
            }
            if (! $this->filled('area')) {
                $validator->errors()->add('area', 'required');
            }
            if ($this->filled('area') && $this->filled('estacion_id') && config('equipos.estrictas')
                && ! Equipo::existeEn((string) $this->input('area'), (int) $this->input('estacion_id'))) {
                $validator->errors()->add('area', 'not_in_station');
            }
        }];
    }
}
