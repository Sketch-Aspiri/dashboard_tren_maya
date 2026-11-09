<?php

namespace Database\Factories;

use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<SolicitudInsumoPartida>
 */
class SolicitudInsumoPartidaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'solicitud_insumo_id' => SolicitudInsumo::factory(),
            'numero' => 1,
            'cantidad' => 2,
            'unidad' => 'Pieza',
            'descripcion' => 'Foco LED '.fake()->unique()->numberBetween(1, 99999),
            'justificacion' => 'Reposición',
        ];
    }
}
