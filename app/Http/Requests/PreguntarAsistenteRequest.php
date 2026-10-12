<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Pregunta al asistente del dashboard. El historial son los últimos turnos
 * de la conversación en pantalla (texto plano) para preguntas de
 * seguimiento ("¿y en Tulum?").
 */
class PreguntarAsistenteRequest extends FormRequest
{
    private const MAX_TURNOS = 4;

    public function authorize(): bool
    {
        return Gate::allows('usar-asistente');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'pregunta' => ['required', 'string', 'min:2', 'max:1000'],
            'historial' => ['nullable', 'array', 'max:'.self::MAX_TURNOS],
            'historial.*.pregunta' => ['required', 'string', 'max:1000'],
            'historial.*.respuesta' => ['required', 'string', 'max:6000'],
        ];
    }

    /**
     * @return list<array{pregunta: string, respuesta: string}>
     */
    public function historial(): array
    {
        return array_values(array_map(
            fn (array $turno) => ['pregunta' => (string) $turno['pregunta'], 'respuesta' => (string) $turno['respuesta']],
            $this->validated('historial') ?? [],
        ));
    }
}
