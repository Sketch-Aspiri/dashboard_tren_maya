<?php

namespace Database\Factories;

use App\Models\EscaleraElectrica;
use App\Models\Estacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data").
 *
 * @extends Factory<EscaleraElectrica>
 */
class EscaleraElectricaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'identificador' => fake()->bothify('??####'),
            'modelo' => 'OTIS',
            'anio_instalacion' => 2024,
            'tipo' => 'AMBOS',
            'operativo' => 'SI',
            'estado_barandales' => 'Buen estado',
            'estado_boton_paro_emergencia' => 'Buen estado',
            'fecha_ultimo_mantenimiento' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'observaciones' => null,
        ];
    }
}
