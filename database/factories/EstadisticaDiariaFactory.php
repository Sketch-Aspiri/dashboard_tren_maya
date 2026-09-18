<?php

namespace Database\Factories;

use App\Models\Estacion;
use App\Models\EstadisticaDiaria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section) —
 * the real KPI/report shape for this module is still pending the Jefe de
 * Zona's data (see CLAUDE.md "Pendientes bloqueados por información
 * externa").
 *
 * @extends Factory<EstadisticaDiaria>
 */
class EstadisticaDiariaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'fecha' => now()->toDateString(),
            'abordan' => fake()->numberBetween(0, 500),
            'boletos_vendidos' => fake()->numberBetween(0, 500),
            'registrado_por' => null,
        ];
    }
}
