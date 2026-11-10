<?php

namespace App\Http\Requests;

use App\Models\Factura;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generación del PDF de comprobación (una factura o el mes): mes a consultar,
 * si se abre en el navegador (ver) y quién recibe y autoriza, con cargo,
 * nombre y firma digital opcionales. Los textos no pueden llevar marcadores
 * de plantilla ni caracteres de control.
 */
class GenerarComprobacionRequest extends FormRequest
{
    private const TEXTO_PROHIBIDO = '/\$\{|[\x00-\x08\x0B\x0C\x0E-\x1F]/u';

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reglasFirma = [
            'nullable', 'file', 'mimes:png,jpg,jpeg',
            'max:'.config('asistencia_documentos.firma.max_kb'),
            'dimensions:max_width='.config('asistencia_documentos.firma.max_dimension_origen_px').',max_height='.config('asistencia_documentos.firma.max_dimension_origen_px'),
        ];
        $reglas = [
            'anio' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['nullable', 'integer', 'min:1', 'max:12'],
            'ver' => ['nullable', 'boolean'],
            'firmantes' => ['nullable', 'array'],
        ];

        foreach (array_keys(config('insumos.comprobacion.firmantes')) as $clave) {
            $reglas["firmantes.$clave"] = ['nullable', 'array'];
            $reglas["firmantes.$clave.cargo"] = ['nullable', 'string', 'max:200', 'not_regex:'.self::TEXTO_PROHIBIDO];
            $reglas["firmantes.$clave.nombre"] = ['nullable', 'string', 'max:150', 'not_regex:'.self::TEXTO_PROHIBIDO];
            $reglas["firmantes.$clave.firma"] = $reglasFirma;
        }

        return $reglas;
    }
}
