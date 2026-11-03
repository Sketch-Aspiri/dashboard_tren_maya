<?php

namespace App\Http\Resources;

use App\Models\SeccionOrigen;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Lo que la pantalla necesita de un origen. Nunca el enlace compartido ni nada de la cuenta salvo su correo.
 *
 * @mixin SeccionOrigen
 */
class SeccionOrigenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'proveedor' => $this->proveedor,
            'nombre' => $this->nombre,
            'estado' => $this->estado->value,
            'estado_etiqueta' => __($this->estado->label()),
            'alimenta_tablas' => $this->alimenta_tablas,
            'incluir_subcarpetas' => $this->incluir_subcarpetas,
            'sincronizado_en' => $this->sincronizado_en?->toIso8601String(),
            'error' => $this->sincronizacion_error,
            'resumen' => collect($this->resumen ?? [])->except('confirmar_baja_masiva')->all(),
            'cuenta' => $this->cuenta?->cuenta_email,
        ];
    }
}
