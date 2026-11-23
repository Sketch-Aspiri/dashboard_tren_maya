<?php

namespace App\Http\Requests;

use App\Models\VacacionPlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Agregar un día inhábil al calendario del año de la selección. */
class InhabilVacacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gestionar', VacacionPlan::class) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $anio = (int) config('vacaciones.anio_seleccion');

        return [
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$anio.'-01-01', 'before_or_equal:'.$anio.'-12-31'],
            'nombre' => ['required', 'string', 'max:80'],
        ];
    }
}
