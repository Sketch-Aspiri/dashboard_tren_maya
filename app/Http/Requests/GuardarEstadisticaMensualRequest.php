<?php

namespace App\Http\Requests;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use App\Services\EstadisticaDiariaService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validates and authorizes a bulk save of one month of "Estadísticas"
 * (flujo de pasajeros / boletos vendidos) for one estación — a single
 * submission always covers one estación + one mes for every captured day
 * at once, same shape as GuardarAsistenciaCapturaRequest.
 */
class GuardarEstadisticaMensualRequest extends FormRequest
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

        // Delegates the full "who may write this estación" rule to
        // EstadisticaDiariaPolicy::manageFor() — the single source of
        // truth for it, per .claude/rules/code-style.md ("authorization
        // lives only in Policies").
        return $user->can('manageFor', [EstadisticaDiaria::class, $estacion]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
            'dias' => ['array'],
            'dias.*.dia' => ['required', 'integer', 'between:1,31'],
            'dias.*.abordan' => ['nullable', 'integer', 'min:0'],
            'dias.*.boletos_vendidos' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validarDiasDelMes($validator);
        });
    }

    /**
     * The estación this submission targets, resolved from the {estacion}
     * route-bound model — never from a client-writable body field. Memoized
     * so the controller can safely call this again after
     * authorize()+validate() succeed. "Estación"-role accounts always
     * target their own station regardless of the URL, enforced by
     * EstadisticaDiariaService::resolveEstacionObjetivo().
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

        return $this->estacionObjetivo = app(EstadisticaDiariaService::class)
            ->resolveEstacionObjetivo($user, $this->estacionIdInput());
    }

    /**
     * Reads the {estacion} route parameter. By the time a FormRequest is
     * resolved, Laravel's SubstituteBindings middleware has already run,
     * so this is normally an already-bound Estacion instance — but the raw
     * id is handled too, defensively.
     */
    private function estacionIdInput(): ?int
    {
        $routeParam = $this->route('estacion');

        if ($routeParam instanceof Estacion) {
            return $routeParam->id;
        }

        if ($routeParam !== null && is_numeric($routeParam)) {
            return (int) $routeParam;
        }

        return null;
    }

    /**
     * Defense in depth: checkdate() catches days that pass the
     * `between:1,31` rule but don't actually exist for the given mes/anio
     * (e.g. día 31 de abril, or 29 de febrero on a non-leap year).
     */
    private function validarDiasDelMes(Validator $validator): void
    {
        $anio = (int) $this->input('anio');
        $mes = (int) $this->input('mes');

        foreach ((array) $this->input('dias', []) as $index => $fila) {
            $dia = $fila['dia'] ?? null;

            if ($dia === null || ! is_numeric($dia)) {
                // Already reported by the 'required'/'integer' rule.
                continue;
            }

            if (! checkdate($mes, (int) $dia, $anio)) {
                $validator->errors()->add(
                    "dias.{$index}.dia",
                    __('Este día no es válido para el mes y año indicados.'),
                );
            }
        }
    }
}
