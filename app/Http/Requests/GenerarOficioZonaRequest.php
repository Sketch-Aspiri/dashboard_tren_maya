<?php

namespace App\Http\Requests;

use App\Models\DocumentoAsistencia;
use App\Services\AsistenciaDocumentoService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Validates and authorizes generating the zone-wide attendance oficio.
 * The signer is a key of `asistencia_documentos.zona.firmantes` — the name,
 * position and initials themselves always come from server config, never
 * from the request.
 */
class GenerarOficioZonaRequest extends FormRequest
{
    /** Letters, digits, dot, dash, slash and space — the characters real folios use. */
    private const FORMATO_FOLIO = '/^[\pL\pN.\-\/ ]+$/uD';

    public function authorize(): bool
    {
        return $this->user()?->can('generarZona', DocumentoAsistencia::class) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $existente = app(AsistenciaDocumentoService::class)->documentoDeZona($this->fechaObjetivo());

        return [
            'fecha' => ['required', 'date_format:Y-m-d'],
            'numero_oficio' => [
                'required', 'string', 'max:80', 'regex:'.self::FORMATO_FOLIO,
                Rule::unique('documentos_asistencia', 'numero_oficio')->ignore($existente?->id),
            ],
            'firmante' => ['required', 'string', Rule::in(array_keys(config('asistencia_documentos.zona.firmantes')))],
        ];
    }

    public function fechaObjetivo(): CarbonImmutable
    {
        try {
            return $this->filled('fecha') ? CarbonImmutable::parse($this->input('fecha')) : CarbonImmutable::today();
        } catch (Throwable) {
            // A malformed value is reported by rules() as a 422.
            return CarbonImmutable::today();
        }
    }
}
