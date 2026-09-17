<?php

namespace App\Http\Requests;

use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use App\Services\AsistenciaCapturaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validates and authorizes creating a "comisionado visitante" entry (Etapa
 * 2 — Control de Asistencia Diaria, see the approved plan). Reuses
 * AsistenciaCapturaService::resolveEstacionObjetivo() — the same "who
 * targets which estación" resolution GuardarAsistenciaCapturaRequest
 * uses — so an "Estación"-role user can never target another station via
 * a tampered 'estacion' input.
 */
class StoreComisionadoVisitanteRequest extends FormRequest
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

        return $user->can('createFor', [ComisionadoVisitante::class, $estacion]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
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

    /**
     * Adapted from GuardarAsistenciaCapturaRequest::validarRangosDeFecha()
     * to a single-row payload instead of an array of rows.
     */
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
            // Malformed dates are already caught by the 'date' rule.
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
