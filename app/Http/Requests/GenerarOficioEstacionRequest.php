<?php

namespace App\Http\Requests;

use App\Models\DocumentoAsistencia;
use App\Models\Estacion;
use App\Services\AsistenciaCapturaService;
use App\Services\AsistenciaDocumentoService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Validates and authorizes generating one station's attendance oficio.
 * Which estación it targets is resolved server-side (an "Estación" account
 * always gets its own; the client-supplied id is only honored for
 * Administrador), exactly like the capture screen does.
 */
class GenerarOficioEstacionRequest extends FormRequest
{
    /** Letters, digits, dot, dash, slash and space — the characters real folios use. */
    private const FORMATO_FOLIO = '/^[\pL\pN.\-\/ ]+$/uD';

    /** Template placeholders ("${...}") and control characters have no place in a name or position. */
    private const FIRMANTE_PROHIBIDO = '/\$\{|[\x00-\x08\x0B\x0C\x0E-\x1F]/u';

    private ?Estacion $estacionObjetivo = null;

    private bool $estacionResuelta = false;

    public function authorize(): bool
    {
        $user = $this->user();
        $estacion = $this->estacionObjetivo();

        if ($user === null || $estacion === null) {
            return false;
        }

        return $user->can('generarEstacion', [DocumentoAsistencia::class, $estacion, $this->fechaObjetivo()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $existente = $this->documentoExistente();

        return [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'numero_oficio' => [
                'required', 'string', 'max:80', 'regex:'.self::FORMATO_FOLIO,
                Rule::unique('documentos_asistencia', 'numero_oficio')->ignore($existente?->id),
            ],
            'firmante_cargo' => ['required', 'string', 'max:150', 'not_regex:'.self::FIRMANTE_PROHIBIDO],
            'firmante_nombre' => ['required', 'string', 'max:150', 'not_regex:'.self::FIRMANTE_PROHIBIDO],
        ];
    }

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

    public function fechaObjetivo(): CarbonImmutable
    {
        try {
            return $this->filled('fecha') ? CarbonImmutable::parse($this->input('fecha')) : CarbonImmutable::today();
        } catch (Throwable) {
            // A malformed value is reported by rules() as a 422, not masked as a 403.
            return CarbonImmutable::today();
        }
    }

    private function documentoExistente(): ?DocumentoAsistencia
    {
        $estacion = $this->estacionObjetivo();

        return $estacion === null
            ? null
            : app(AsistenciaDocumentoService::class)->documentoDeEstacion($estacion, $this->fechaObjetivo());
    }
}
