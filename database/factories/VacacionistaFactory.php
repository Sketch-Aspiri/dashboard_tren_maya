<?php

namespace Database\Factories;

use App\Models\Vacacionista;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data") — never the
 * real rol de vacaciones of the zone staff.
 *
 * @extends Factory<Vacacionista>
 */
class VacacionistaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'anio' => 2026,
            'no_empleado' => fake()->unique()->numerify('V-#####'),
            'nombre_completo' => fake()->name(),
            'denominacion_puesto' => fake()->jobTitle(),
            'dias_otorgados' => 20,
            'estacion_id' => null,
            'empleado_id' => null,
        ];
    }
}
