<?php

namespace App\Http\Requests;

use App\Models\Estacion;
use App\Models\OficioComision;
use App\Services\AsistenciaCapturaService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sube uno o varios oficios de comisión (PDF, Word o imagen) de una estación.
 * La estación se resuelve en el servidor igual que en la captura: una cuenta
 * "Estación" siempre sube a la suya.
 */
class SubirOficiosComisionRequest extends FormRequest
{
    private ?Estacion $estacion = null;

    private bool $resuelta = false;

    public function authorize(): bool
    {
        $estacion = $this->estacionObjetivo();

        return $estacion !== null && $this->user()?->can('gestionarDe', [OficioComision::class, $estacion]) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivos' => ['required', 'array', 'min:1', 'max:10'],
            'archivos.*' => [
                'file', 'mimes:pdf,doc,docx,jpg,jpeg,png',
                'max:'.config('asistencia_documentos.oficios_comision.max_kb'),
            ],
            'vigente_hasta' => ['nullable', 'date_format:Y-m-d'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    public function estacionObjetivo(): ?Estacion
    {
        if ($this->resuelta) {
            return $this->estacion;
        }

        $this->resuelta = true;
        $user = $this->user();

        return $this->estacion = $user === null ? null : app(AsistenciaCapturaService::class)
            ->resolveEstacionObjetivo($user, $this->filled('estacion') ? (int) $this->input('estacion') : null);
    }
}
