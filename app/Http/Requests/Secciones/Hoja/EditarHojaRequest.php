<?php

namespace App\Http\Requests\Secciones\Hoja;

use App\Http\Requests\Secciones\ConTablaDeSeccion;
use App\Models\Seccion;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base de las peticiones que modifican el contenido de una hoja (celdas,
 * filas, diseño, orden): la hoja llega en "tabla" (ConTablaDeSeccion) y se
 * exige editarHoja sobre ella (una estación sin permiso: 404; con Ver: 403;
 * una hoja espejo de la nube: 403).
 */
abstract class EditarHojaRequest extends FormRequest
{
    use ConTablaDeSeccion;

    public function authorize(): bool|Response
    {
        $usuario = $this->user();

        return $usuario === null ? false : Gate::forUser($usuario)->inspect('editarHoja', [$this->seccion(), $this->tabla()]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->reglaTabla() + $this->reglasHoja();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    abstract protected function reglasHoja(): array;

    protected function seccion(): Seccion
    {
        return $this->route('seccion');
    }
}
