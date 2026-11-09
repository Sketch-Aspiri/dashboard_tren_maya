<?php

namespace Database\Factories;

use App\Models\Proveedor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<Proveedor>
 */
class ProveedorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nombre' => 'Proveedor '.fake()->unique()->numberBetween(1, 99999),
            'iva' => 8,
        ];
    }
}
