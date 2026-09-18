<?php

namespace Database\Factories;

use App\Models\Vacacionista;
use App\Models\VacacionPeriodo;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VacacionPeriodo>
 */
class VacacionPeriodoFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vacacionista_id' => Vacacionista::factory(),
            'trimestre' => 1,
            'fecha_inicio' => '2026-02-02',
            'fecha_termino' => '2026-02-06',
            'dias_solicitados' => 5,
        ];
    }
}
