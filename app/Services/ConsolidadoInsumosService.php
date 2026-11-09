<?php

namespace App\Services;

use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use Illuminate\Support\Collection;

/**
 * Consolidado mensual de solicitudes de insumos de la zona: un bloque por
 * estación con sus partidas aprobadas, totales con IVA y el saldo del fondo
 * rotatorio, más las alertas de revisión. Sustituye la parte de consolidación
 * del Flujo 1 de n8n; solo cuentan las solicitudes APROBADAS.
 */
final class ConsolidadoInsumosService
{
    public const MESES = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio',
        7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre',
    ];

    public function __construct(private readonly SolicitudInsumoService $solicitudes) {}

    /**
     * @return array{
     *     anio: int, mes: int, mesNombre: string,
     *     bloques: list<array{estacion: Estacion, solicitud: SolicitudInsumo|null, incluida: bool, partidas: Collection<int, SolicitudInsumoPartida>, subtotal: float, iva: float, total: float}>,
     *     subtotal: float, iva: float, total: float, tope: float, pendiente: float
     * }
     */
    public function datos(int $anio, int $mes): array
    {
        $solicitudes = SolicitudInsumo::query()
            ->with(['partidas.catalogo'])
            ->where('anio', $anio)->where('mes', $mes)
            ->get()->keyBy('estacion_id');

        $bloques = $this->estacionesEnOrden()->map(function (Estacion $estacion) use ($solicitudes) {
            $solicitud = $solicitudes->get($estacion->id);
            $incluida = $solicitud?->estado === SolicitudInsumo::APROBADA;
            $partidas = $incluida ? $solicitud->partidas : collect();
            $subtotal = round($partidas->sum(fn (SolicitudInsumoPartida $p) => $p->total() ?? 0.0), 2);
            $iva = round($subtotal * SolicitudInsumoService::IVA, 2);

            return [
                'estacion' => $estacion,
                'solicitud' => $solicitud,
                'incluida' => $incluida,
                'partidas' => $partidas,
                'subtotal' => $subtotal,
                'iva' => $iva,
                'total' => round($subtotal + $iva, 2),
            ];
        })->values();

        $subtotal = round($bloques->sum('subtotal'), 2);
        $iva = round($bloques->sum('iva'), 2);
        $total = round($bloques->sum('total'), 2);

        return [
            'anio' => $anio,
            'mes' => $mes,
            'mesNombre' => self::MESES[$mes],
            'bloques' => $bloques->all(),
            'subtotal' => $subtotal,
            'iva' => $iva,
            'total' => $total,
            'tope' => SolicitudInsumoService::TOPE_FONDO,
            'pendiente' => round(SolicitudInsumoService::TOPE_FONDO - $total, 2),
        ];
    }

    /**
     * Alertas para el CONTROL_FILTRO: estaciones sin solicitud o sin
     * aprobar, precios atípicos, partidas sin partida presupuestal o sin
     * proveedor y fondo excedido.
     *
     * @param  array<string, mixed>  $datos  Salida de datos().
     * @return list<array{estacion: string, descripcion: string, cantidad: float|null, precio: float|null, estado: string, alertas: string, enAnexo: bool}>
     */
    public function alertas(array $datos): array
    {
        $filas = [];

        foreach ($datos['bloques'] as $bloque) {
            $nombre = $bloque['estacion']->nombre;
            $solicitud = $bloque['solicitud'];

            if ($solicitud === null) {
                $filas[] = $this->fila($nombre, '(sin solicitud)', null, null, 'Revisar', 'SIN_SOLICITUD', false);

                continue;
            }

            if (! $bloque['incluida']) {
                $filas[] = $this->fila($nombre, '(solicitud '.$solicitud->estado.')', null, null, 'Retenido', 'PENDIENTE_APROBACION', false);

                continue;
            }

            $atipicas = $this->solicitudes->partidasAtipicas($solicitud);

            foreach ($bloque['partidas'] as $partida) {
                $alertas = array_filter([
                    in_array($partida->id, $atipicas, true) ? 'PRECIO_ATIPICO' : null,
                    $partida->precio_unitario === null ? 'SIN_PRECIO' : null,
                    blank($partida->partida_presupuestal) ? 'SIN_PARTIDA_PRESUPUESTAL' : null,
                    blank($partida->proveedor) ? 'SIN_PROVEEDOR' : null,
                ]);

                if ($alertas !== []) {
                    $filas[] = $this->fila($nombre, $partida->descripcion, (float) $partida->cantidad,
                        $partida->precio_unitario === null ? null : (float) $partida->precio_unitario,
                        'Revisar', implode(', ', $alertas), true);
                }
            }
        }

        if ($datos['total'] > $datos['tope']) {
            $filas[] = $this->fila('Zona Oriente', 'Total con IVA $'.number_format($datos['total'], 2).' excede el fondo rotatorio de $'.number_format($datos['tope'], 2),
                null, null, 'Revisar', 'EXCEDE_FONDO', true);
        }

        return $filas;
    }

    /**
     * Una fila por insumo (misma descripción y unidad), sumando todas las
     * estaciones, para el LISTADO POR PRODUCTO.
     *
     * @param  array<string, mixed>  $datos  Salida de datos().
     * @return list<array{descripcion: string, cantidad: float, unidad: string, estaciones: string, precio: float, importe: float, observaciones: string, proveedor: string, revision: string}>
     */
    public function listadoPorProducto(array $datos): array
    {
        $grupos = [];

        foreach ($datos['bloques'] as $bloque) {
            foreach ($bloque['partidas'] as $partida) {
                $clave = mb_strtolower(trim($partida->descripcion)).'|'.mb_strtolower($partida->unidad);
                $grupos[$clave][] = [$bloque['estacion']->nombre, $partida];
            }
        }

        $filas = [];

        foreach ($grupos as $items) {
            $partidas = array_column($items, 1);
            $cantidad = round(array_sum(array_map(fn ($p) => (float) $p->cantidad, $partidas)), 2);
            $importe = round(array_sum(array_map(fn ($p) => $p->total() ?? 0.0, $partidas)), 2);
            $precios = array_unique(array_map(fn ($p) => (string) $p->precio_unitario, $partidas));

            $filas[] = [
                'descripcion' => $partidas[0]->descripcion,
                'cantidad' => $cantidad,
                'unidad' => $partidas[0]->unidad,
                'estaciones' => implode(', ', array_unique(array_column($items, 0))),
                'precio' => $cantidad > 0 ? round($importe / $cantidad, 2) : 0.0,
                'importe' => $importe,
                'observaciones' => $this->unir(array_map(fn ($p) => $p->justificacion, $partidas)),
                'proveedor' => $this->unir(array_map(fn ($p) => $p->proveedor, $partidas)),
                'revision' => count($precios) > 1 ? 'PRECIOS_DISTINTOS (precio promedio ponderado)' : '',
            ];
        }

        usort($filas, fn (array $a, array $b) => strcasecmp($a['descripcion'], $b['descripcion']));

        return $filas;
    }

    /**
     * Orden del ANEXO: estaciones por código 23..31 y al final el Edificio
     * Zonal Este (00).
     *
     * @return Collection<int, Estacion>
     */
    private function estacionesEnOrden(): Collection
    {
        return Estacion::query()->get()->sortBy(fn (Estacion $e) => $e->codigo === '00' || $e->codigo === null
            ? '99'.$e->orden
            : $e->codigo)->values();
    }

    /**
     * @param  list<string|null>  $textos
     */
    private function unir(array $textos): string
    {
        $unicos = array_unique(array_filter(array_map(fn ($t) => trim((string) $t), $textos)));

        return mb_substr(implode(' / ', $unicos), 0, 500);
    }

    /**
     * @return array{estacion: string, descripcion: string, cantidad: float|null, precio: float|null, estado: string, alertas: string, enAnexo: bool}
     */
    private function fila(string $estacion, string $descripcion, ?float $cantidad, ?float $precio, string $estado, string $alertas, bool $enAnexo): array
    {
        return compact('estacion', 'descripcion', 'cantidad', 'precio', 'estado', 'alertas') + ['enAnexo' => $enAnexo];
    }
}
