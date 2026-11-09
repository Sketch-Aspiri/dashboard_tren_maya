<?php

namespace Database\Factories;

use App\Models\CatalogoInsumo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<CatalogoInsumo>
 */
class CatalogoInsumoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'descripcion' => 'Insumo de prueba '.fake()->unique()->numberBetween(1, 99999),
            'unidad' => 'Pieza',
            'ultimo_precio' => 50,
            'activo' => true,
        ];
    }
}
