<?php

namespace App\Services;

use App\Enums\TipoServicio;
use App\Models\Estacion;
use App\Models\PagoServicio;
use App\Models\ServicioEstacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lógica de "Estadísticas" -> Gasto energético: el resumen anual de la zona,
 * y la captura/corrección de los servicios y pagos mensuales de una estación
 * (los datos iniciales entran por app:import-gasto-energetico). Los totales se
 * calculan aquí, no se persisten.
 */
final class GastoEnergeticoService
{
    private const MESES = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];

    /**
     * @return list<int> Años con pagos cargados, del más reciente al más antiguo.
     */
    public function aniosDisponibles(): array
    {
        return PagoServicio::query()
            ->distinct()
            ->orderByDesc('anio')
            ->pluck('anio')
            ->map(fn ($anio) => (int) $anio)
            ->all();
    }

    /**
     * Un bloque por tipo de servicio, con una fila por estación (catálogo en
     * orden) y los pagos de los 12 meses del año. Un mes sin dato es null;
     * un pago de $0 es 0.
     *
     * @return list<array{
     *     tipo: TipoServicio,
     *     filas: list<array{servicio: ServicioEstacion, meses: array<int, float|null>, total: float}>,
     *     totalesMensuales: array<int, float>,
     *     total: float
     * }>
     */
    public function resumenAnual(int $anio): array
    {
        $servicios = ServicioEstacion::query()
            ->with(['estacion', 'pagos' => fn ($pagos) => $pagos->where('anio', $anio)])
            ->get()
            ->sortBy(fn (ServicioEstacion $servicio) => $servicio->estacion->orden);

        return array_map(
            fn (TipoServicio $tipo) => $this->resumenDeTipo($tipo, $servicios->filter(fn (ServicioEstacion $servicio) => $servicio->tipo === $tipo)),
            TipoServicio::cases(),
        );
    }

    /**
     * Un bloque por tipo de servicio para la pantalla de captura de una
     * estación: el servicio (null si la estación aún no lo tiene) y sus pagos
     * del año, indexados por mes (1..12).
     *
     * @return list<array{tipo: TipoServicio, servicio: ServicioEstacion|null, pagos: Collection<int, PagoServicio>}>
     */
    public function datosDeEstacion(Estacion $estacion, int $anio): array
    {
        $servicios = ServicioEstacion::query()
            ->where('estacion_id', $estacion->id)
            ->with(['pagos' => fn ($pagos) => $pagos->where('anio', $anio)])
            ->get()
            ->keyBy(fn (ServicioEstacion $servicio) => $servicio->tipo->value);

        return array_map(fn (TipoServicio $tipo) => [
            'tipo' => $tipo,
            'servicio' => $servicios->get($tipo->value),
            'pagos' => ($servicios->get($tipo->value)?->pagos ?? collect())->keyBy('mes'),
        ], TipoServicio::cases());
    }

    /**
     * Guarda en bloque lo capturado para una estación y año: datos del
     * servicio (proveedor, contrato, observaciones) y los pagos de los meses
     * con monto. Un mes en blanco se omite (nunca borra ni crea un pago en
     * cero); borrar un pago es una acción explícita aparte. Un servicio que la
     * estación no tenía solo se crea si se capturó algo.
     *
     * @param  array<string, array{proveedor?: ?string, contrato?: ?string, observaciones?: ?string, meses?: array<int|string, mixed>}>  $servicios  por valor de TipoServicio
     */
    public function guardar(Estacion $estacion, int $anio, array $servicios): void
    {
        DB::transaction(function () use ($estacion, $anio, $servicios) {
            foreach ($servicios as $tipo => $datos) {
                $this->guardarServicio($estacion, TipoServicio::from($tipo), $anio, $datos);
            }
        });
    }

    /**
     * @param  array{proveedor?: ?string, contrato?: ?string, observaciones?: ?string, meses?: array<int|string, mixed>}  $datos
     */
    private function guardarServicio(Estacion $estacion, TipoServicio $tipo, int $anio, array $datos): void
    {
        $atributos = [
            'proveedor' => $this->textoONulo($datos['proveedor'] ?? null),
            'contrato' => $this->textoONulo($datos['contrato'] ?? null),
            'observaciones' => $this->textoONulo($datos['observaciones'] ?? null),
        ];
        $montos = array_filter($datos['meses'] ?? [], fn ($monto) => ! blank($monto));

        $servicio = ServicioEstacion::query()
            ->where('estacion_id', $estacion->id)
            ->where('tipo', $tipo->value)
            ->lockForUpdate()
            ->first();

        if ($servicio === null && array_filter($atributos) === [] && $montos === []) {
            return;
        }

        $servicio ??= new ServicioEstacion(['estacion_id' => $estacion->id, 'tipo' => $tipo->value]);
        $servicio->fill($atributos)->save();

        foreach ($montos as $mes => $monto) {
            PagoServicio::query()->updateOrCreate(
                ['servicio_estacion_id' => $servicio->id, 'anio' => $anio, 'mes' => (int) $mes],
                ['monto' => $monto],
            );
        }
    }

    private function textoONulo(?string $texto): ?string
    {
        $texto = trim((string) $texto);

        return $texto === '' ? null : $texto;
    }

    /**
     * @param  Collection<int, ServicioEstacion>  $servicios
     * @return array{tipo: TipoServicio, filas: list<array{servicio: ServicioEstacion, meses: array<int, float|null>, total: float}>, totalesMensuales: array<int, float>, total: float}
     */
    private function resumenDeTipo(TipoServicio $tipo, Collection $servicios): array
    {
        $filas = $servicios->map(function (ServicioEstacion $servicio) {
            $montos = $servicio->pagos->mapWithKeys(fn (PagoServicio $pago) => [$pago->mes => (float) $pago->monto]);
            $meses = array_combine(self::MESES, array_map(fn (int $mes) => $montos->get($mes), self::MESES));

            return ['servicio' => $servicio, 'meses' => $meses, 'total' => (float) array_sum($meses)];
        })->values()->all();

        $totalesMensuales = array_combine(self::MESES, array_map(
            fn (int $mes) => (float) array_sum(array_column(array_column($filas, 'meses'), $mes)),
            self::MESES,
        ));

        return [
            'tipo' => $tipo,
            'filas' => $filas,
            'totalesMensuales' => $totalesMensuales,
            'total' => (float) array_sum($totalesMensuales),
        ];
    }
}
