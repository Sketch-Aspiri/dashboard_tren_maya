<?php

namespace App\Http\Requests;

use App\Models\AfluenciaDiaria;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class GuardarAfluenciaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', AfluenciaDiaria::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'anio' => ['required', 'integer', 'between:2020,2100'],
            'mes' => ['required', 'integer', 'between:1,12'],
            'dias' => ['required', 'array', 'max:31'],
            'dias.*' => ['array'],
            'dias.*.abordan' => ['nullable', 'integer', 'min:0', 'max:99999'],
            'dias.*.boletos' => ['nullable', 'integer', 'min:0', 'max:99999'],
        ];
    }

    /** @return array<int, callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $primero = CarbonImmutable::create((int) $this->input('anio'), (int) $this->input('mes'), 1, 0, 0, 0, 'America/Cancun');
            $hoy = CarbonImmutable::now('America/Cancun')->startOfDay();

            foreach ($this->input('dias') as $dia => $valores) {
                $dia = (int) $dia;

                if ($dia < 1 || $dia > $primero->daysInMonth) {
                    $validator->errors()->add("dias.$dia", __('El día :d no existe en ese mes.', ['d' => $dia]));
                } elseif ($primero->day($dia)->greaterThan($hoy) && (($valores['abordan'] ?? '') !== '' || ($valores['boletos'] ?? '') !== '')) {
                    $validator->errors()->add("dias.$dia", __('El día :d aún no llega; no se puede capturar.', ['d' => $dia]));
                }
            }
        }];
    }
}
