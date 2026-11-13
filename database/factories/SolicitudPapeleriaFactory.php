<?php

namespace Database\Factories;

use App\Models\Estacion;
use App\Models\SolicitudPapeleria;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only.
 *
 * @extends Factory<SolicitudPapeleria>
 */
class SolicitudPapeleriaFactory extends Factory
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
            'estado' => SolicitudPapeleria::BORRADOR,
        ];
    }

    public function enviada(): static
    {
        return $this->state(fn () => ['estado' => SolicitudPapeleria::ENVIADA, 'enviada_at' => now()]);
    }

    public function aprobada(): static
    {
        return $this->state(fn () => ['estado' => SolicitudPapeleria::APROBADA, 'enviada_at' => now(), 'revisada_at' => now()]);
    }
}
