<?php

namespace App\Http\Requests;

use App\Models\Empleado;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validates and authorizes updating a "comisionado fuera" entry. `fecha`
 * is deliberately not editable — same reasoning as
 * UpdateComisionadoVisitanteRequest. `empleado_id` may be changed, but
 * only to another empleado already on the entry's own estación roster
 * (the estación itself never moves via update).
 */
class UpdateComisionadoFueraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('comisionadoFuera')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'empleado_id' => ['required', 'integer', 'exists:empleados,id'],
            'coordinacion_destino' => ['nullable', 'string', 'max:255'],
            'ubicacion_destino' => ['nullable', 'string', 'max:255'],
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
            $this->validarRosterDeLaEstacion($validator);
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

    private function validarRosterDeLaEstacion(Validator $validator): void
    {
        $estacion = $this->route('comisionadoFuera')?->estacion;
        $empleadoId = $this->input('empleado_id');

        if ($estacion === null || blank($empleadoId)) {
            return;
        }

        $perteneceAlRoster = Empleado::query()
            ->where('id', (int) $empleadoId)
            ->where('estacion_id', $estacion->id)
            ->exists();

        if (! $perteneceAlRoster) {
            $validator->errors()->add(
                'empleado_id',
                __('Este empleado no pertenece a la estación seleccionada.'),
            );
        }
    }
}
