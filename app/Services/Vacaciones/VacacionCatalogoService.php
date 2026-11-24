<?php

namespace App\Services\Vacaciones;

use App\Models\VacacionCatalogoPeriodo;
use App\Models\VacacionDiaInhabil;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Genera el catálogo de periodos del año con la misma regla del Excel 2026: cada lunes (o el
 * siguiente día hábil si es inhábil) empieza un periodo de 5 y otro de 10 días hábiles netos de
 * inhábiles ("26 Ene. al 9 Feb." = 10 por el 2 de feb). Quedan "no autorizados" los que tocan
 * Semana Santa y Pascua y los que terminan después del segundo viernes de diciembre.
 * El trimestre es el del mes de inicio.
 */
final class VacacionCatalogoService
{
    /**
     * Inhábiles de la regla de 2026 (1 ene, 1.er lunes de feb, 3.er lunes de mar, 1 y 5 may, 16 sep, 3.er lunes
     * de nov, 25 dic), recalculados para el año. La Zona los confirma en pantalla.
     *
     * @return array<string, string> fecha => nombre
     */
    public function inhabilesPorDefecto(int $anio): array
    {
        $lunes = fn (int $mes, int $n) => CarbonImmutable::create($anio, $mes, 1)->nthOfMonth($n, CarbonImmutable::MONDAY)->toDateString();

        return [
            "$anio-01-01" => 'Año Nuevo',
            $lunes(2, 1) => 'Día de la Constitución',
            $lunes(3, 3) => 'Natalicio de Benito Juárez',
            "$anio-05-01" => 'Día del Trabajo',
            "$anio-05-05" => 'Batalla de Puebla',
            "$anio-09-16" => 'Independencia',
            $lunes(11, 3) => 'Revolución Mexicana',
            "$anio-12-25" => 'Navidad',
        ];
    }

    /** Domingo de Pascua (algoritmo gregoriano anónimo). */
    public function pascua(int $anio): CarbonImmutable
    {
        $a = $anio % 19;
        $b = intdiv($anio, 100);
        $c = $anio % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($anio, $mes, $dia);
    }

    /**
     * Crea (o completa) inhábiles y periodos del año sin tocar lo que la Zona ya editó.
     *
     * @return array{periodos: int, inhabiles: int}
     */
    public function generar(int $anio): array
    {
        return DB::transaction(function () use ($anio) {
            $inhabiles = 0;
            // Si la Zona ya tiene calendario del año, no se le agregan fechas por defecto.
            $yaHabiaCalendario = VacacionDiaInhabil::query()->where('anio', $anio)->exists();

            foreach ($yaHabiaCalendario ? [] : $this->inhabilesPorDefecto($anio) as $fecha => $nombre) {
                $inhabiles += VacacionDiaInhabil::query()->firstOrCreate(['anio' => $anio, 'fecha' => $fecha], ['nombre' => $nombre])->wasRecentlyCreated ? 1 : 0;
            }

            $feriados = VacacionDiaInhabil::query()->where('anio', $anio)->pluck('fecha')->map(fn ($f) => $f->toDateString())->all();
            $periodos = 0;

            foreach ($this->candidatos($anio, $feriados) as $c) {
                $existente = VacacionCatalogoPeriodo::query()->where(['anio' => $anio, 'inicio' => $c['inicio'], 'dias' => $c['dias']])->first();

                if ($existente !== null) {
                    continue;
                }

                VacacionCatalogoPeriodo::query()->create($c + ['anio' => $anio]);
                $periodos++;
            }

            return ['periodos' => $periodos, 'inhabiles' => $inhabiles];
        });
    }

    /**
     * @param  list<string>  $feriados
     * @return list<array{inicio: string, fin: string, dias: int, trimestre: int, autorizado: bool, nota: string|null}>
     */
    private function candidatos(int $anio, array $feriados): array
    {
        $pascua = $this->pascua($anio);
        $bloqueoIni = $pascua->subDays(6); // Lunes Santo
        $bloqueoFin = $pascua->addDays(5); // Viernes de la semana de Pascua
        $tope = CarbonImmutable::create($anio, 12, 11);
        $topeViernes = $tope->dayOfWeek === CarbonImmutable::FRIDAY ? $tope : $tope->previous(CarbonImmutable::FRIDAY);
        $salida = [];

        for ($lunes = CarbonImmutable::create($anio, 1, 1)->startOfWeek(); $lunes->year <= $anio; $lunes = $lunes->addWeek()) {
            if ($lunes->year < $anio && $lunes->addDays(4)->year < $anio) {
                continue;
            }

            $inicio = $this->primerHabil($lunes, $feriados);

            if ($inicio->year !== $anio || $inicio->dayOfWeek === CarbonImmutable::SATURDAY) {
                continue;
            }

            foreach ([5, 10] as $dias) {
                $fin = $this->cierre($inicio, $dias, $feriados);
                $semanaSanta = $inicio->lte($bloqueoFin) && $fin->gte($bloqueoIni);
                $diciembre = $fin->gt($topeViernes);

                $salida[] = [
                    'inicio' => $inicio->toDateString(), 'fin' => $fin->toDateString(), 'dias' => $dias,
                    'trimestre' => intdiv($inicio->month - 1, 3) + 1,
                    'autorizado' => ! $semanaSanta && ! $diciembre,
                    'nota' => $semanaSanta ? 'Semana Santa y Pascua' : ($diciembre ? 'Cierre de año' : null),
                ];
            }
        }

        return $salida;
    }

    /** @param  list<string>  $feriados */
    private function primerHabil(CarbonImmutable $dia, array $feriados): CarbonImmutable
    {
        while ($dia->isWeekend() || in_array($dia->toDateString(), $feriados, true)) {
            $dia = $dia->addDay();
        }

        return $dia;
    }

    /**
     * Último día del periodo que suma $dias hábiles netos desde $inicio.
     *
     * @param  list<string>  $feriados
     */
    private function cierre(CarbonImmutable $inicio, int $dias, array $feriados): CarbonImmutable
    {
        $cuenta = 0;
        $dia = $inicio;

        while (true) {
            if (! $dia->isWeekend() && ! in_array($dia->toDateString(), $feriados, true)) {
                $cuenta++;

                if ($cuenta === $dias) {
                    return $dia;
                }
            }

            $dia = $dia->addDay();
        }
    }
}
