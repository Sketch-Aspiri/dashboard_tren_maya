<?php

namespace App\Services\Cumpleanos;

use App\Enums\EmpleadoEstatus;
use App\Models\Empleado;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cumpleaños del personal activo. La fecha de nacimiento es dato personal: solo se leen nombre,
 * estación y fecha, y a quien consulta se le entrega únicamente el día y el mes (nunca año ni edad).
 * Quien nació un 29 de febrero lo celebra el 28 en los años que no son bisiestos.
 */
final class CumpleanosService
{
    /**
     * @return Collection<int, array{nombre: string, estacion: string|null, estacion_id: int|null, mes: int, dia: int}>
     */
    public function todos(?int $estacionId = null, ?string $busqueda = null): Collection
    {
        $consulta = Empleado::query()
            ->with('estacion:id,nombre')
            ->where('estatus', EmpleadoEstatus::Activo)
            ->whereNotNull('fecha_nacimiento')
            ->select(['id', 'nombre_completo', 'estacion_id', 'fecha_nacimiento']);

        if ($estacionId !== null) {
            $consulta->where('estacion_id', $estacionId);
        }

        if ($busqueda !== null && trim($busqueda) !== '') {
            $consulta->where('nombre_completo', 'like', '%'.addcslashes(trim($busqueda), '%_\\').'%');
        }

        return $consulta->orderBy('nombre_completo')->get()->map(fn (Empleado $e) => [
            'nombre' => (string) $e->nombre_completo,
            'estacion' => $e->estacion?->nombre,
            'estacion_id' => $e->estacion_id,
            'mes' => (int) $e->fecha_nacimiento->month,
            'dia' => (int) $e->fecha_nacimiento->day,
        ])->values();
    }

    /**
     * Quienes celebran ese día (mes y día), con la regla del 29 de febrero.
     *
     * @return Collection<int, array{nombre: string, estacion: string|null, estacion_id: int|null, mes: int, dia: int}>
     */
    public function deHoy(CarbonImmutable $fecha): Collection
    {
        return $this->todos()->filter(fn (array $c) => $this->celebraEn($c, $fecha))->values();
    }

    /**
     * Los que cumplen entre mañana y dentro de $dias días, en orden de calendario.
     *
     * @param  Collection<int, array{nombre: string, estacion: string|null, estacion_id: int|null, mes: int, dia: int}>  $cumpleanos
     * @return Collection<int, array{fecha: CarbonImmutable, cumple: array<string, mixed>}>
     */
    public function proximos(Collection $cumpleanos, CarbonImmutable $hoy, int $dias = 30): Collection
    {
        $salida = collect();

        for ($i = 1; $i <= $dias; $i++) {
            $dia = $hoy->addDays($i);

            foreach ($cumpleanos as $c) {
                if ($this->celebraEn($c, $dia)) {
                    $salida->push(['fecha' => $dia, 'cumple' => $c]);
                }
            }
        }

        return $salida;
    }

    /**
     * @param  array{mes: int, dia: int}  $cumple
     */
    public function celebraEn(array $cumple, CarbonImmutable $fecha): bool
    {
        if ($cumple['mes'] === 2 && $cumple['dia'] === 29 && ! $fecha->isLeapYear()) {
            return $fecha->month === 2 && $fecha->day === 28;
        }

        return $fecha->month === $cumple['mes'] && $fecha->day === $cumple['dia'];
    }
}
