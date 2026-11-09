<?php

namespace Database\Seeders;

use App\Models\Estacion;
use Illuminate\Database\Seeder;

/**
 * Seeds the fixed Zona Oriente station catalog: the 9 operational stations
 * plus "Edificio Zonal Este" (the zone office, not an operational station
 * but present in the daily attendance oficio — see the Fase 1 plan).
 * Idempotent (updateOrCreate per row; also backfills `codigo`, the numeric
 * code of the historical insumos folders), safe to re-run.
 */
class EstacionSeeder extends Seeder
{
    /**
     * @var list<array{nombre: string, codigo: string, is_operativa: bool, orden: int}>
     */
    private const ESTACIONES = [
        ['nombre' => 'Bacalar', 'codigo' => '29', 'is_operativa' => true, 'orden' => 1],
        ['nombre' => 'Chetumal', 'codigo' => '30', 'is_operativa' => true, 'orden' => 2],
        ['nombre' => 'Felipe Carrillo Puerto', 'codigo' => '27', 'is_operativa' => true, 'orden' => 3],
        ['nombre' => 'Kohunlich', 'codigo' => '31', 'is_operativa' => true, 'orden' => 4],
        ['nombre' => 'Limones', 'codigo' => '28', 'is_operativa' => true, 'orden' => 5],
        ['nombre' => 'Playa del Carmen', 'codigo' => '24', 'is_operativa' => true, 'orden' => 6],
        ['nombre' => 'Puerto Morelos', 'codigo' => '23', 'is_operativa' => true, 'orden' => 7],
        ['nombre' => 'Tulum', 'codigo' => '25', 'is_operativa' => true, 'orden' => 8],
        ['nombre' => 'Tulum Aeropuerto', 'codigo' => '26', 'is_operativa' => true, 'orden' => 9],
        ['nombre' => 'Edificio Zonal Este', 'codigo' => '00', 'is_operativa' => false, 'orden' => 10],
    ];

    public function run(): void
    {
        foreach (self::ESTACIONES as $estacion) {
            Estacion::query()->updateOrCreate(
                ['nombre' => $estacion['nombre']],
                $estacion,
            );
        }
    }
}
