<?php

namespace Database\Factories;

use App\Enums\EstatusAsistencia;
use App\Models\Empleado;
use App\Models\Estacion;
use App\Models\RegistroDiario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dummy data only (per .claude/rules/testing.md "Dummy Data" section).
 *
 * @extends Factory<RegistroDiario>
 */
class RegistroDiarioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'empleado_id' => Empleado::factory(),
            'estacion_id' => Estacion::factory(),
            'fecha' => now()->toDateString(),
            'estatus' => EstatusAsistencia::Presente->value,
            'fecha_inicio' => null,
            'fecha_fin' => null,
            'notas' => null,
            'registrado_por' => null,
        ];
    }
}
