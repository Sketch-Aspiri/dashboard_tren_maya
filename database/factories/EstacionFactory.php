<?php

namespace Database\Factories;

use App\Models\Estacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only — the real station catalog is seeded via
 * database/seeders/EstacionSeeder.php, never generated randomly.
 *
 * @extends Factory<Estacion>
 */
class EstacionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->city(),
            'is_operativa' => true,
            'orden' => fake()->numberBetween(1, 20),
        ];
    }
}
