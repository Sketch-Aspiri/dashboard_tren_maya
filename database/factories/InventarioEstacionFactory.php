<?php

namespace Database\Factories;

use App\Models\Estacion;
use App\Models\InventarioEstacion;
use App\Services\InventarioEstacionService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<InventarioEstacion>
 */
class InventarioEstacionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $descripcion = 'Herramienta de prueba '.fake()->unique()->numberBetween(1, 99999);

        return [
            'estacion_id' => Estacion::factory(),
            'tipo' => InventarioEstacion::HERRAMIENTA,
            'clave' => InventarioEstacionService::clave($descripcion),
            'descripcion' => $descripcion,
            'unidad' => 'Pieza',
            'cantidad' => 1,
            'primer_mes' => '2026-04',
            'ultimo_mes' => '2026-04',
            'fuente' => 'resguardos',
        ];
    }
}
