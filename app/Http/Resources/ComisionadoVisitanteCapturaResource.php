<?php

namespace App\Http\Resources;

use App\Models\ComisionadoVisitante;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One visitor from another coordination as the capture screen's Alpine component edits it.
 *
 * @mixin ComisionadoVisitante
 */
class ComisionadoVisitanteCapturaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => (string) $this->nombre,
            'no_trabajador' => (string) $this->no_trabajador,
            'direccion_origen' => (string) $this->direccion_origen,
            'motivo' => (string) $this->motivo,
            'fecha_inicio' => $this->fecha_inicio?->toDateString() ?? '',
            'fecha_fin' => $this->fecha_fin?->toDateString() ?? '',
            'notas' => (string) $this->notas,
        ];
    }
}
