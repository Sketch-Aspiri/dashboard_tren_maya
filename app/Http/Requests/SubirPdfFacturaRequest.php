<?php

namespace App\Http\Requests;

use App\Models\Factura;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PDF de respaldo de una factura ya cargada desde su XML.
 */
class SubirPdfFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('factura') instanceof Factura && $this->user()?->can('manage', Factura::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivo' => ['required', 'file', 'extensions:pdf', 'mimes:pdf', 'max:10240'],
        ];
    }
}
