<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Http\Requests\Secciones\ConTablaDeSeccion;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Carga de una hoja completa para la cuadrícula (?tabla=<id>). Una hoja que
 * el usuario no puede abrir no pasa la validación (ConTablaDeSeccion).
 */
class VerHojaRequest extends FormRequest
{
    use ConTablaDeSeccion;

    public function authorize(): bool|Response
    {
        return $this->user() ? Gate::forUser($this->user())->inspect('view', $this->route('seccion')) : false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->reglaTabla();
    }
}
