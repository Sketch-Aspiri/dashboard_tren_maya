<?php

namespace App\Http\Requests;

use App\Models\ComisionadoFuera;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Services\AsistenciaCapturaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validates and authorizes creating a "comisionado fuera" entry (Etapa 2 —
 * Control de Asistencia Diaria, see the approved plan). Reuses
 * AsistenciaCapturaService::resolveEstacionObjetivo(), same as
 * StoreComisionadoVisitanteRequest.
 */
class StoreComisionadoFueraRequest extends FormRequest
{
    private ?Estacion $estacionObjetivo = null;

    private bool $estacionResuelta = false;

    public function authorize(): bool
    {
        $user = $this->user();
        $estacion = $this->estacionObjetivo();

        if ($user === null || $estacion === null) {
            return false;
        }

        return $user->can('createFor', [ComisionadoFuera::class, $estacion]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
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

    /**
     * The estación this submission targets. Memoized, same pattern as
     * GuardarAsistenciaCapturaRequest::estacionObjetivo().
     */
    public function estacionObjetivo(): ?Estacion
    {
        if ($this->estacionResuelta) {
            return $this->estacionObjetivo;
        }

        $this->estacionResuelta = true;

        $user = $this->user();

        if ($user === null) {
            return $this->estacionObjetivo = null;
        }

        $estacionIdInput = $this->filled('estacion') ? (int) $this->input('estacion') : null;

        return $this->estacionObjetivo = app(AsistenciaCapturaService::class)
            ->resolveEstacionObjetivo($user, $estacionIdInput);
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

    /**
     * Defense in depth: never trust the client to only submit an empleado
     * from the target estación's own roster, even though the UI's <select>
     * won't offer other stations' employees. Same pattern as
     * GuardarAsistenciaCapturaRequest::validarRosterDeLaEstacion(), adapted
     * to a single empleado_id field instead of an array of rows.
     */
    private function validarRosterDeLaEstacion(Validator $validator): void
    {
        $estacion = $this->estacionObjetivo();
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
