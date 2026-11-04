<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Http\Requests\Secciones\ConTablaDeSeccion;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Columna nueva desde la cuadrícula: es estructura (update + modificarTabla).
 */
class InsertarColumnaHojaRequest extends FormRequest
{
    use ConTablaDeSeccion;

    public function authorize(): bool|Response
    {
        $usuario = $this->user();

        if ($usuario === null || ! $usuario->can('update', $this->route('seccion'))) {
            return false;
        }

        return Gate::forUser($usuario)->inspect('modificarTabla', [$this->route('seccion'), $this->tabla()]);
    }

    public function rules(): array
    {
        return $this->reglaTabla() + [
            'antes_de' => ['nullable', 'string', 'max:64'],
            'nombre' => ['nullable', 'string', 'max:120'],
        ];
    }
}
