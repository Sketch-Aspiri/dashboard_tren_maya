<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Models\SeccionColumna;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Renombrar o redimensionar una columna desde la cuadrícula (estructura).
 */
class ActualizarColumnaHojaRequest extends FormRequest
{
    public const ANCHO_MINIMO = 40;

    public const ANCHO_MAXIMO = 800;

    public function authorize(): bool|Response
    {
        $usuario = $this->user();

        if ($usuario === null || ! $usuario->can('update', $this->route('seccion'))) {
            return false;
        }

        return Gate::forUser($usuario)->inspect('modificarTabla', [$this->route('seccion'), $this->columna()->tabla]);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'required', 'string', 'max:120'],
            'ancho' => ['sometimes', 'nullable', 'integer', 'min:'.self::ANCHO_MINIMO, 'max:'.self::ANCHO_MAXIMO],
        ];
    }

    public function columna(): SeccionColumna
    {
        return $this->route('columna');
    }
}
