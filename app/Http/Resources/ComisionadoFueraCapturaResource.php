<?php

namespace App\Http\Resources;

use App\Models\ComisionadoFuera;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One commission of the station's own personnel as the capture screen's Alpine component edits it.
 *
 * @mixin ComisionadoFuera
 */
class ComisionadoFueraCapturaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'empleado_id' => $this->empleado_id,
            'coordinacion_destino' => (string) $this->coordinacion_destino,
            'ubicacion_destino' => (string) $this->ubicacion_destino,
            'motivo' => (string) $this->motivo,
            'fecha_inicio' => $this->fecha_inicio?->toDateString() ?? '',
            'fecha_fin' => $this->fecha_fin?->toDateString() ?? '',
            'notas' => (string) $this->notas,
        ];
    }
}
