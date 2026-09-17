<?php

namespace App\Services;

use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use App\Models\User;

/**
 * Business logic for "Personal comisionado de otras coordinaciones
 * presentes hoy" (Etapa 2 — Control de Asistencia Diaria). Keeps
 * ComisionadoVisitanteController thin per this project's layering
 * convention. estacion_id and registrado_por are always server-set here —
 * never taken from client input.
 */
final class ComisionadoVisitanteService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Estacion $estacion, array $data, User $actor): ComisionadoVisitante
    {
        return ComisionadoVisitante::create([
            ...$data,
            'estacion_id' => $estacion->id,
            'registrado_por' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ComisionadoVisitante $comisionadoVisitante, array $data): ComisionadoVisitante
    {
        $comisionadoVisitante->update($data);

        return $comisionadoVisitante;
    }

    public function delete(ComisionadoVisitante $comisionadoVisitante): void
    {
        $comisionadoVisitante->delete();
    }
}
