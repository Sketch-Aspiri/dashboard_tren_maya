<?php

namespace App\Services\Vacaciones;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Los días de vacaciones del rol se cuentan en días hábiles (lunes a viernes, ambos extremos
 * incluidos): 13–17 abr = 5, 4–11 may = 6, 4–18 may = 11.
 */
final class VacacionCalculo
{
    public static function diasHabiles(CarbonInterface $inicio, CarbonInterface $fin): int
    {
        if ($fin->lt($inicio)) {
            return 0;
        }

        $dias = 0;

        for ($dia = CarbonImmutable::instance($inicio)->startOfDay(); $dia->lte($fin); $dia = $dia->addDay()) {
            if (! $dia->isWeekend()) {
                $dias++;
            }
        }

        return $dias;
    }
}
