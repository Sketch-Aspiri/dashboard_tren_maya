<?php

namespace App\Http\Requests\Secciones;

use App\Enums\TipoColumna;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición de una columna. Las opciones de una lista llegan en un
 * textarea, una por renglón.
 */
class GuardarColumnaRequest extends FormRequest
{
    private const MAX_OPCIONES = 100;

    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('seccion')) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $opciones = preg_split('/\r\n|\r|\n/', (string) $this->input('opciones_texto', '')) ?: [];

        $this->merge([
            'opciones' => array_values(array_unique(array_filter(array_map('trim', $opciones), fn (string $opcion) => $opcion !== ''))),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:120'],
            'tipo' => ['required', Rule::enum(TipoColumna::class)],
            'opciones' => ['array', 'max:'.self::MAX_OPCIONES, Rule::requiredIf(fn () => $this->input('tipo') === TipoColumna::Lista->value)],
            'opciones.*' => ['string', 'max:120'],
        ];
    }

    public function messages(): array
    {
        return [
            'opciones.required' => __('Escribe al menos una opción para la lista (una por renglón).'),
        ];
    }

    public function tipo(): TipoColumna
    {
        return TipoColumna::from($this->validated('tipo'));
    }

    /**
     * @return list<string>
     */
    public function opciones(): array
    {
        return $this->validated('opciones', []);
    }
}
