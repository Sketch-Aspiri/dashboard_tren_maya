<?php

namespace App\Http\Requests\Interno;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rango de fechas (máximo ~un año) con el que el portal pide las capas de su calendario. La autorización la da la firma
 * del puente (VerificarPuentePortal), no una cuenta de persona.
 */
class RangoCalendarioRequest extends FormRequest
{
    public const MAX_DIAS = 400;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ];
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->desde()->diffInDays($this->hasta()) > self::MAX_DIAS) {
                $validator->errors()->add('hasta', 'El rango máximo es de '.self::MAX_DIAS.' días.');
            }
        }];
    }

    public function desde(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->query('desde'));
    }

    public function hasta(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->query('hasta'));
    }
}
