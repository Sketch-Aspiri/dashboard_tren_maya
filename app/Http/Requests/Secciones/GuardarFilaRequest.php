<?php

namespace App\Http\Requests\Secciones;

use App\Enums\TipoColumna;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionTabla;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta y edición manual de una fila. Las reglas se arman a partir de las
 * columnas de su tabla; no hay regla para "datos" completo, así que
 * validated() solo devuelve claves de columnas existentes (cualquier campo
 * extra enviado por el cliente se descarta).
 */
class GuardarFilaRequest extends FormRequest
{
    use ConTablaDeSeccion {
        tabla as private tablaElegidaEnFormulario;
    }

    public function authorize(): bool
    {
        return $this->user()?->can('gestionarDatos', $this->seccion()) ?? false;
    }

    /**
     * Al editar, la tabla es la de la fila; al crear, la del campo oculto
     * "tabla" (o la primera).
     */
    public function tabla(): SeccionTabla
    {
        return $this->route('fila')?->tabla ?? $this->tablaElegidaEnFormulario();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->reglaTabla() + $this->tabla()->columnas
            ->mapWithKeys(fn (SeccionColumna $columna) => ['datos.'.$columna->clave => $this->reglasPara($columna)])
            ->all();
    }

    public function attributes(): array
    {
        return $this->tabla()->columnas
            ->mapWithKeys(fn (SeccionColumna $columna) => ['datos.'.$columna->clave => $columna->nombre])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function valores(): array
    {
        return $this->validated('datos', []);
    }

    /**
     * @return list<mixed>
     */
    private function reglasPara(SeccionColumna $columna): array
    {
        return match ($columna->tipo) {
            TipoColumna::Numero => ['nullable', 'numeric'],
            TipoColumna::Fecha => ['nullable', 'date'],
            TipoColumna::Booleano => ['nullable', 'boolean'],
            TipoColumna::Lista => ['nullable', 'string', Rule::in($columna->listaOpciones())],
            TipoColumna::Texto => ['nullable', 'string', 'max:5000'],
        };
    }

    private function seccion(): Seccion
    {
        return $this->route('seccion');
    }
}
