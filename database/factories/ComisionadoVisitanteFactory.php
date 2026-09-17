<?php

namespace Database\Factories;

use App\Models\ComisionadoVisitante;
use App\Models\Estacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section).
 *
 * @extends Factory<ComisionadoVisitante>
 */
class ComisionadoVisitanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'fecha' => now()->toDateString(),
            'no_trabajador' => fake()->optional()->numerify('#####'),
            'nombre' => fake()->name(),
            'direccion_origen' => fake()->optional()->company(),
            'motivo' => fake()->optional()->sentence(4),
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'notas' => null,
            'registrado_por' => null,
        ];
    }
}
