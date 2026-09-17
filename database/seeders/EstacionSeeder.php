<?php

namespace Database\Seeders;

use App\Models\Estacion;
use Illuminate\Database\Seeder;

/**
 * Seeds the fixed Zona Oriente station catalog: the 9 operational stations
 * plus "Edificio Zonal Este" (the zone office, not an operational station
 * but present in the daily attendance oficio — see the Fase 1 plan).
 * Idempotent (firstOrCreate per row), safe to re-run.
 */
class EstacionSeeder extends Seeder
{
    /**
     * @var list<array{nombre: string, is_operativa: bool, orden: int}>
     */
    private const ESTACIONES = [
        ['nombre' => 'Bacalar', 'is_operativa' => true, 'orden' => 1],
        ['nombre' => 'Chetumal', 'is_operativa' => true, 'orden' => 2],
        ['nombre' => 'Felipe Carrillo Puerto', 'is_operativa' => true, 'orden' => 3],
        ['nombre' => 'Kohunlich', 'is_operativa' => true, 'orden' => 4],
        ['nombre' => 'Limones', 'is_operativa' => true, 'orden' => 5],
        ['nombre' => 'Playa del Carmen', 'is_operativa' => true, 'orden' => 6],
        ['nombre' => 'Puerto Morelos', 'is_operativa' => true, 'orden' => 7],
        ['nombre' => 'Tulum', 'is_operativa' => true, 'orden' => 8],
        ['nombre' => 'Tulum Aeropuerto', 'is_operativa' => true, 'orden' => 9],
        ['nombre' => 'Edificio Zonal Este', 'is_operativa' => false, 'orden' => 10],
    ];

    public function run(): void
    {
        foreach (self::ESTACIONES as $estacion) {
            Estacion::query()->firstOrCreate(
                ['nombre' => $estacion['nombre']],
                $estacion,
            );
        }
    }
}
