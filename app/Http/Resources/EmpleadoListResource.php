<?php

namespace App\Http\Resources;

use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Empleado
 *
 * Slim resource for the personal listing/AJAX endpoints, which only
 * display a handful of directory columns. Deliberately excludes CURP,
 * RFC, NSS, domicilio, tipo_sangre, alergias, fecha_nacimiento and
 * contacto_emergencia_* — that PII is only needed (and only served) on the
 * single-record `show` view via EmpleadoResource, not embedded into every
 * page load/search result for a full page of employees.
 */
class EmpleadoListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no_empleado' => $this->no_empleado,
            'estatus' => $this->estatus->value,
            'estatus_label' => $this->estatus->label(),
            'estacion_codigo' => $this->estacion_codigo,
            'plaza_actual' => $this->plaza_actual,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $this->puesto,
            'telefono' => $this->telefono,
        ];
    }
}
