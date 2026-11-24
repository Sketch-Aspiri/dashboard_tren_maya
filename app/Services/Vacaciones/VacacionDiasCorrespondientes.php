<?php

namespace App\Services\Vacaciones;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Días de vacaciones del año según la fecha de ingreso (tabla del Excel 2026, recorrida al año):
 * ingreso hasta el 16 de enero del año → 20; del 17 de enero al 30 de junio → 10; después → ninguno.
 * Sin fecha de ingreso se asumen 20. La Zona puede corregir el valor de cada plan.
 */
final class VacacionDiasCorrespondientes
{
    public static function para(?CarbonInterface $ingreso, int $anio): int
    {
        if ($ingreso === null) {
            return 20;
        }

        $ingreso = CarbonImmutable::instance($ingreso)->startOfDay();

        if ($ingreso->lte(CarbonImmutable::create($anio, 1, 16))) {
            return 20;
        }

        return $ingreso->lte(CarbonImmutable::create($anio, 6, 30)) ? 10 : 0;
    }
}
