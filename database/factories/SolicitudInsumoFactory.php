<?php

namespace Database\Factories;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<SolicitudInsumo>
 */
class SolicitudInsumoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estacion_id' => Estacion::factory(),
            'anio' => 2026,
            'mes' => 12,
            'tipo_servicio' => '1er escalón',
            'lugar_trabajo' => 'Estación de prueba',
            'estado' => SolicitudInsumo::BORRADOR,
        ];
    }

    public function enviada(): static
    {
        return $this->state(fn () => ['estado' => SolicitudInsumo::ENVIADA, 'enviada_at' => now()]);
    }
}
