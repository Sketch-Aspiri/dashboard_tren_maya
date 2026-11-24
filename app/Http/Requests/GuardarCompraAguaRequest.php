<?php

namespace App\Http\Requests;

use App\Models\AguaRegistro;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Una compra de garrafones: fecha, cantidad y comprobante (PDF o imagen) obligatorio. */
class GuardarCompraAguaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $registro = $this->route('registro');

        return $registro instanceof AguaRegistro && $this->user()?->can('update', $registro) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fecha' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'garrafones' => ['required', 'integer', 'between:1,500'],
            'nota' => ['nullable', 'string', 'max:255'],
            'comprobante' => ['required', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimes:pdf,jpg,jpeg,png', 'max:'.(int) config('agua.comprobante_kb')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'comprobante.required' => __('Adjunta el comprobante (ticket o nota) en PDF o imagen.'),
            'comprobante.max' => __('El comprobante pesa demasiado (máximo :kb MB).', ['kb' => (int) config('agua.comprobante_kb') / 1024]),
            'fecha.before_or_equal' => __('La fecha de la compra no puede ser futura.'),
        ];
    }
}
