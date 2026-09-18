<?php

namespace Database\Factories;

use App\Enums\TipoServicio;
use App\Models\Estacion;
use App\Models\ServicioEstacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data").
 *
 * @extends Factory<ServicioEstacion>
 */
class ServicioEstacionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'tipo' => TipoServicio::EnergiaElectrica->value,
            'proveedor' => 'CFE',
            'contrato' => fake()->numerify('############'),
            'observaciones' => null,
        ];
    }

    public function agua(): static
    {
        return $this->state(fn () => ['tipo' => TipoServicio::Agua->value, 'proveedor' => 'CAPA']);
    }
}
