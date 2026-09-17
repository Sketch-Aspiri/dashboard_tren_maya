<?php

namespace App\Http\Requests;

use App\Enums\EstatusAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use App\Services\AsistenciaCapturaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

/**
 * Validates and authorizes a bulk save of the daily attendance capture
 * screen (Fase 1 — Control de Asistencia Diaria). A single submission
 * always covers one estación + one fecha for every roster row at once —
 * there is no standalone single-row create/update route.
 */
class GuardarAsistenciaCapturaRequest extends FormRequest
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

        // Delegates the full "who may write this estación+fecha" rule to
        // RegistroDiarioPolicy::captureForFecha() — the single source of
        // truth for it, per .claude/rules/code-style.md ("authorization
        // lives only in Policies"). A malformed/missing 'fecha' is treated
        // as today here so a bad value falls through to rules()'s
        // required/date checks as a 422, rather than authorize() masking
        // it as a 403.
        return $user->can('captureForFecha', [RegistroDiario::class, $estacion, $this->fechaParaAutorizar()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date'],
            'registros' => ['required', 'array', 'min:1'],
            'registros.*.empleado_id' => ['required', 'integer', 'exists:empleados,id'],
            'registros.*.estatus' => ['required', Rule::enum(EstatusAsistencia::class)],
            'registros.*.fecha_inicio' => ['nullable', 'date'],
            'registros.*.fecha_fin' => ['nullable', 'date'],
            'registros.*.notas' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validarRangosDeFecha($validator);
            $this->validarRosterDeLaEstacion($validator);
        });
    }

    /**
     * The estación this submission targets. Memoized so the controller can
     * safely call this again after authorize()+validate() succeed without
     * re-resolving (and without ever trusting a value the client could
     * have changed between calls).
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
     * The 'fecha' value used for the authorize()-time Policy check. Missing
     * or malformed input resolves to today (the most permissive case for
     * an Estación-role user) so a bad value is reported by rules() as a
     * 422, not swallowed as a 403 by authorize().
     */
    private function fechaParaAutorizar(): CarbonImmutable
    {
        $fecha = $this->input('fecha');

        if (blank($fecha)) {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::parse($fecha);
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }

    private function validarRangosDeFecha(Validator $validator): void
    {
        foreach ((array) $this->input('registros', []) as $index => $fila) {
            $inicio = $fila['fecha_inicio'] ?? null;
            $fin = $fila['fecha_fin'] ?? null;

            if (blank($inicio) || blank($fin)) {
                continue;
            }

            try {
                $esValido = CarbonImmutable::parse($fin)->greaterThanOrEqualTo(CarbonImmutable::parse($inicio));
            } catch (Throwable) {
                // Malformed dates are already caught by the 'date' rule.
                continue;
            }

            if (! $esValido) {
                $validator->errors()->add(
                    "registros.{$index}.fecha_fin",
                    __('La fecha de fin debe ser igual o posterior a la fecha de inicio.'),
                );
            }
        }
    }

    /**
     * Defense in depth: never trust the client to only submit its own
     * roster, even though the UI won't offer other stations' employees.
     */
    private function validarRosterDeLaEstacion(Validator $validator): void
    {
        $estacion = $this->estacionObjetivo();

        if ($estacion === null) {
            return;
        }

        $idsDelRoster = Empleado::query()->where('estacion_id', $estacion->id)->pluck('id')->all();

        foreach ((array) $this->input('registros', []) as $index => $fila) {
            $empleadoId = $fila['empleado_id'] ?? null;

            if ($empleadoId !== null && ! in_array((int) $empleadoId, $idsDelRoster, true)) {
                $validator->errors()->add(
                    "registros.{$index}.empleado_id",
                    __('Este empleado no pertenece a la estación seleccionada.'),
                );
            }
        }
    }
}
