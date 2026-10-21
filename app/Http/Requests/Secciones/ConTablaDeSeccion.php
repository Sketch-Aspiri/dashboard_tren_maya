<?php

namespace App\Http\Requests\Secciones;

use App\Models\SeccionTabla;
use Illuminate\Validation\Rule;

/**
 * Elección de la tabla de una sección con ?tabla=<id> (o un campo oculto
 * "tabla" en los formularios). Si no viene se usa la primera; un id de otra
 * sección o dado de baja no pasa la validación.
 */
trait ConTablaDeSeccion
{
    private ?SeccionTabla $tablaElegida = null;

    /**
     * @return array<string, array<mixed>>
     */
    protected function reglaTabla(): array
    {
        return [
            'tabla' => [
                'nullable',
                'integer',
                Rule::exists('seccion_tablas', 'id')->where('seccion_id', $this->route('seccion')->id)->whereNull('deleted_at'),
            ],
        ];
    }

    public function tabla(): SeccionTabla
    {
        $id = $this->input('tabla');

        return $this->tablaElegida ??= $this->route('seccion')->tablaActiva(is_numeric($id) ? (int) $id : null);
    }
}
