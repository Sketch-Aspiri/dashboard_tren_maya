<?php

namespace Database\Factories;

use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagoServicio>
 */
class PagoServicioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'servicio_estacion_id' => ServicioEstacion::factory(),
            'anio' => 2026,
            'mes' => 1,
            'monto' => 1000,
        ];
    }
}
