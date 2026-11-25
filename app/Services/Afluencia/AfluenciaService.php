<?php

namespace App\Services\Afluencia;

use App\Models\AfluenciaDiaria;
use App\Models\Estacion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Consulta y captura del flujo de pasajeros (abordan y boletos vendidos por estación y día). */
class AfluenciaService
{
    /** @return array{anio: int, mes: int, dia: int} */
    public function mesActual(): array
    {
        $hoy = CarbonImmutable::now('America/Cancun');

        return ['anio' => $hoy->year, 'mes' => $hoy->month, 'dia' => $hoy->day];
    }

    /** @return Collection<int, Estacion> */
    public function estaciones(): Collection
    {
        return Estacion::query()->where('is_operativa', true)->orderBy('orden')->get();
    }

    /** Días del mes que ya pudieron capturarse (todo el mes si ya pasó; hasta hoy si es el actual). */
    public function diasEsperados(int $anio, int $mes): int
    {
        $hoy = $this->mesActual();
        $clave = $anio * 100 + $mes;
        $actual = $hoy['anio'] * 100 + $hoy['mes'];

        if ($clave > $actual) {
            return 0;
        }

        return $clave === $actual ? $hoy['dia'] : CarbonImmutable::create($anio, $mes, 1)->daysInMonth;
    }

    /** Días del mes que ya vencieron (hasta ayer): el día en curso todavía no cuenta como faltante. */
    public function diasVencidos(int $anio, int $mes): int
    {
        $hoy = $this->mesActual();
        $esteMes = $anio === $hoy['anio'] && $mes === $hoy['mes'];

        return $esteMes ? $hoy['dia'] - 1 : $this->diasEsperados($anio, $mes);
    }

    /**
     * Días sin captura hasta ayer, en el mes al que pertenece ayer (el día 1 revisa el mes anterior).
     *
     * @return array{anio: int, mes: int, faltan: int}
     */
    public function faltantesHastaAyer(Estacion $estacion): array
    {
        $ayer = CarbonImmutable::now('America/Cancun')->subDay();
        $capturados = AfluenciaDiaria::query()->where('estacion_id', $estacion->id)
            ->whereDate('fecha', '>=', $ayer->startOfMonth()->toDateString())
            ->whereDate('fecha', '<=', $ayer->toDateString())
            ->count();

        return ['anio' => $ayer->year, 'mes' => $ayer->month, 'faltan' => max(0, $ayer->day - $capturados)];
    }

    /**
     * Totales del mes por estación. Sin ningún dato, el total es null (no cero).
     *
     * @return Collection<int, array{estacion: Estacion, abordan: int|null, boletos: int|null, capturados: int, faltan: int}>
     */
    public function tablero(int $anio, int $mes): Collection
    {
        $vencidos = $this->diasVencidos($anio, $mes);
        $totales = AfluenciaDiaria::query()
            ->whereYear('fecha', $anio)->whereMonth('fecha', $mes)
            ->selectRaw('estacion_id, SUM(abordan) as abordan, SUM(boletos) as boletos, COUNT(*) as dias, SUM(DAY(fecha) <= ?) as dias_vencidos', [$vencidos])
            ->groupBy('estacion_id')
            ->get()->keyBy('estacion_id');

        return $this->estaciones()->map(function (Estacion $estacion) use ($totales, $vencidos): array {
            $t = $totales->get($estacion->id);

            return [
                'estacion' => $estacion,
                'abordan' => $t?->abordan === null ? null : (int) $t->abordan,
                'boletos' => $t?->boletos === null ? null : (int) $t->boletos,
                'capturados' => (int) ($t->dias ?? 0),
                'faltan' => max(0, $vencidos - (int) ($t->dias_vencidos ?? 0)),
            ];
        });
    }

    /**
     * Captura del mes de una estación indexada por día (1..N).
     *
     * @return array<int, array{abordan: int|null, boletos: int|null}>
     */
    public function dias(Estacion $estacion, int $anio, int $mes): array
    {
        $salida = [];

        AfluenciaDiaria::query()->where('estacion_id', $estacion->id)
            ->whereYear('fecha', $anio)->whereMonth('fecha', $mes)->get()
            ->each(function (AfluenciaDiaria $r) use (&$salida): void {
                $salida[(int) $r->fecha->day] = ['abordan' => $r->abordan, 'boletos' => $r->boletos];
            });

        return $salida;
    }

    /**
     * Guarda los días recibidos: ambos campos vacíos borran el día; con uno solo, el otro queda sin dato.
     *
     * @param  array<int|string, array{abordan?: mixed, boletos?: mixed}>  $dias
     * @return array{guardados: int, borrados: int}
     */
    public function guardar(Estacion $estacion, int $anio, int $mes, array $dias, ?User $usuario): array
    {
        $resultado = ['guardados' => 0, 'borrados' => 0];

        DB::transaction(function () use ($estacion, $anio, $mes, $dias, $usuario, &$resultado): void {
            foreach ($dias as $dia => $valores) {
                $fecha = CarbonImmutable::create($anio, $mes, (int) $dia)->toDateString();
                $abordan = $this->entero($valores['abordan'] ?? null);
                $boletos = $this->entero($valores['boletos'] ?? null);
                $existente = AfluenciaDiaria::query()->where('estacion_id', $estacion->id)->whereDate('fecha', $fecha)->first();

                if ($abordan === null && $boletos === null) {
                    if ($existente !== null) {
                        $existente->delete();
                        $resultado['borrados']++;
                    }

                    continue;
                }

                if ($existente !== null && $existente->abordan === $abordan && $existente->boletos === $boletos) {
                    continue;
                }

                AfluenciaDiaria::query()->updateOrCreate(
                    ['estacion_id' => $estacion->id, 'fecha' => $fecha],
                    ['abordan' => $abordan, 'boletos' => $boletos, 'capturado_por' => $usuario?->id],
                );
                $resultado['guardados']++;
            }
        });

        return $resultado;
    }

    /**
     * Todo el año por estación y fecha, para el Excel.
     *
     * @return array<int, array<string, array{abordan: int|null, boletos: int|null}>> estacion_id => fecha => valores
     */
    public function anio(int $anio): array
    {
        $salida = [];

        AfluenciaDiaria::query()->whereYear('fecha', $anio)->get()->each(function (AfluenciaDiaria $r) use (&$salida): void {
            $salida[$r->estacion_id][$r->fecha->toDateString()] = ['abordan' => $r->abordan, 'boletos' => $r->boletos];
        });

        return $salida;
    }

    private function entero(mixed $valor): ?int
    {
        return $valor === null || $valor === '' ? null : (int) $valor;
    }
}
