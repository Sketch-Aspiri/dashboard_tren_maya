<?php

namespace App\Services;

use App\Models\ComisionadoFuera;
use App\Models\Empleado;
use App\Models\User;

/**
 * Business logic for "Personal de Zona Oriente comisionado fuera hoy"
 * (Etapa 2 — Control de Asistencia Diaria). Keeps
 * ComisionadoFueraController thin per this project's layering convention.
 * estacion_id and registrado_por are always server-set here — never taken
 * from client input. estacion_id is resolved from the target Empleado's
 * own estacion_id at write time (denormalized, per the migration).
 */
final class ComisionadoFueraService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): ComisionadoFuera
    {
        $empleado = Empleado::query()->findOrFail($data['empleado_id']);

        return ComisionadoFuera::create([
            ...$data,
            'empleado_id' => $empleado->id,
            'estacion_id' => $empleado->estacion_id,
            'registrado_por' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ComisionadoFuera $comisionadoFuera, array $data): ComisionadoFuera
    {
        if (array_key_exists('empleado_id', $data)) {
            $empleado = Empleado::query()->findOrFail($data['empleado_id']);
            $data['estacion_id'] = $empleado->estacion_id;
        }

        $comisionadoFuera->update($data);

        return $comisionadoFuera;
    }

    public function delete(ComisionadoFuera $comisionadoFuera): void
    {
        $comisionadoFuera->delete();
    }
}
