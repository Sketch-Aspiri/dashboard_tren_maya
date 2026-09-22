<?php

namespace App\Http\Resources;

use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Empleado
 */
class EmpleadoResource extends JsonResource
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
            'estacion' => $this->whenLoaded('estacion', fn () => $this->estacion === null ? null : [
                'id' => $this->estacion->id,
                'nombre' => $this->estacion->nombre,
            ]),
            'plaza_actual' => $this->plaza_actual,
            'nombre_completo' => $this->nombre_completo,
            'puesto' => $this->puesto,
            'nivel_plaza' => $this->nivel_plaza,
            'ultimo_grado_estudios' => $this->ultimo_grado_estudios,
            'titulo' => $this->titulo,
            'cedula' => $this->cedula,
            'fecha_ingreso' => $this->fecha_ingreso?->toDateString(),
            'telefono' => $this->telefono,
            'correo' => $this->correo,
            'tipo_sangre' => $this->tipo_sangre,
            'alergias' => $this->alergias,
            'fecha_nacimiento' => $this->fecha_nacimiento?->toDateString(),
            'lugar_nacimiento' => $this->lugar_nacimiento,
            'estado_civil' => $this->estado_civil,
            'curp' => $this->curp,
            'rfc' => $this->rfc,
            'nss' => $this->nss,
            'domicilio' => $this->domicilio,
            'contacto_emergencia_nombre' => $this->contacto_emergencia_nombre,
            'contacto_emergencia_telefono' => $this->contacto_emergencia_telefono,
            'desempeno' => $this->desempeno,
            'edad_en_numero' => $this->edad_en_numero,
            'meses_en_activo' => $this->meses_en_activo,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
