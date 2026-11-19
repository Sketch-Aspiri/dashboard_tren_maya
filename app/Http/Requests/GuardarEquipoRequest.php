<?php

namespace App\Http\Requests;

use App\Models\Equipo;
use App\Support\Areas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuardarEquipoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La Policy se aplica en el controlador.
    }

    protected function prepareForValidation(): void
    {
        // La clave nace del nombre si no se escribe; luego no cambia (la comparten Portal y Tickets).
        if ($this->isMethod('POST') && ! $this->filled('clave') && $this->filled('nombre')) {
            $this->merge(['clave' => Str::slug((string) $this->input('nombre'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $equipo = $this->route('equipo');

        return [
            'nombre' => ['required', 'string', 'min:2', 'max:100', 'regex:/^[^\p{C}]+$/u'],
            'clave' => $equipo instanceof Equipo ? ['prohibited'] : [
                'required', 'string', 'max:32', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('equipos', 'clave'), Rule::notIn([Areas::JEFE]),
            ],
            'estaciones' => ['nullable', 'array'],
            'estaciones.*' => ['integer', 'exists:estaciones,id'],
            'grupos' => ['nullable', 'array'],
            'grupos.*' => ['integer', 'exists:menu_grupos,id'],
        ];
    }
}
