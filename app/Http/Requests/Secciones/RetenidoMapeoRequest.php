<?php

namespace App\Http\Requests\Secciones;

use App\Models\SeccionMapeo;
use App\Models\SeccionOrigen;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Qué mapeo (hoja) con cambio masivo retenido se aplica o se ignora; debe ser del origen de la ruta.
 */
class RetenidoMapeoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->route('seccion')) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var SeccionOrigen $origen */
        $origen = $this->route('origen');

        return [
            'mapeo' => ['required', 'integer', Rule::exists('seccion_mapeos', 'id')->where('seccion_origen_id', $origen->id)->whereNotNull('retenido')],
        ];
    }

    public function mapeo(): SeccionMapeo
    {
        /** @var SeccionOrigen $origen */
        $origen = $this->route('origen');

        return $origen->mapeos()->with('origen.seccion', 'tabla.seccion', 'archivo')->findOrFail((int) $this->validated('mapeo'));
    }
}
