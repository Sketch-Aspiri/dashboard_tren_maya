<?php

namespace App\Http\Requests;

use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La estación pide cambiar el turno de una persona (route: {turno}).
 */
class CrearHorarioSolicitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        $turno = $this->route('turno');

        return $turno instanceof HorarioTurno && $this->user()?->can('solicitar', $turno) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in([HorarioSolicitud::CAMBIO_TURNO, HorarioSolicitud::CAMBIO_FECHA])],
            'nueva_jornada' => ['required_if:tipo,'.HorarioSolicitud::CAMBIO_TURNO, 'nullable', 'string', 'max:255'],
            'nueva_comida' => ['nullable', 'string', 'max:100'],
            'nueva_fecha' => ['required_if:tipo,'.HorarioSolicitud::CAMBIO_FECHA, 'nullable', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nueva_jornada.required_if' => __('Escribe la jornada nueva.'),
            'nueva_fecha.required_if' => __('Elige la fecha con la que se intercambia.'),
            'motivo.required' => __('Escribe el motivo del cambio.'),
            'motivo.min' => __('Detalla un poco más el motivo del cambio (mínimo 10 caracteres).'),
        ];
    }
}
