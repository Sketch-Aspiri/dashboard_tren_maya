<?php

namespace Database\Factories;

use App\Models\Estacion;
use App\Models\EstatusViaAnden;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data").
 *
 * @extends Factory<EstatusViaAnden>
 */
class EstatusViaAndenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'via' => fake()->numberBetween(1, 5),
            'anden' => fake()->randomElement(['A', 'B']),
            'estatus_anden' => 'Terminado',
            'estatus_via' => 'Operativas',
            'senaletica' => 'Instaladas',
            'teleindicadores' => 'Instalados, sin operar',
            'pruebas_galibo' => 'Realizadas',
            'riesgos_obstaculos' => 'Ninguno',
            'comentarios' => null,
        ];
    }
}
