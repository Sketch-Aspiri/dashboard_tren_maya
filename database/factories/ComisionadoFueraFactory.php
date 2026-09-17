<?php

namespace Database\Factories;

use App\Models\ComisionadoFuera;
use App\Models\Empleado;
use App\Models\Estacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section).
 *
 * @extends Factory<ComisionadoFuera>
 */
class ComisionadoFueraFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empleado_id' => Empleado::factory(),
            'estacion_id' => Estacion::factory(),
            'fecha' => now()->toDateString(),
            'coordinacion_destino' => fake()->optional()->randomElement(['CGOFP', 'CGGIF']),
            'ubicacion_destino' => fake()->optional()->city(),
            'motivo' => fake()->optional()->sentence(4),
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'notas' => null,
            'registrado_por' => null,
        ];
    }
}
