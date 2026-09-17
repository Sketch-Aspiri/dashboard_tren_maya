<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validates and authorizes updating a "comisionado visitante" entry.
 * `fecha` is deliberately not editable here — it identifies which day's
 * list this entry belongs to; correcting the day means delete + recreate,
 * not a silent move.
 */
class UpdateComisionadoVisitanteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('comisionadoVisitante')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:255'],
            'no_trabajador' => ['nullable', 'string', 'max:255'],
            'direccion_origen' => ['nullable', 'string', 'max:255'],
            'motivo' => ['nullable', 'string', 'max:255'],
            'fecha_inicio' => ['nullable', 'date'],
            'fecha_fin' => ['nullable', 'date'],
            'notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validarRangoDeFecha($validator);
        });
    }

    private function validarRangoDeFecha(Validator $validator): void
    {
        $inicio = $this->input('fecha_inicio');
        $fin = $this->input('fecha_fin');

        if (blank($inicio) || blank($fin)) {
            return;
        }

        try {
            $esValido = CarbonImmutable::parse($fin)->greaterThanOrEqualTo(CarbonImmutable::parse($inicio));
        } catch (Throwable) {
            return;
        }

        if (! $esValido) {
            $validator->errors()->add(
                'fecha_fin',
                __('La fecha de fin debe ser igual o posterior a la fecha de inicio.'),
            );
        }
    }
}
