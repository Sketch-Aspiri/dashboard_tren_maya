<?php

namespace App\Services;

use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lógica de "Recursos materiales" -> Solicitud de insumos (fondo rotatorio):
 * captura de la estación, revisión de la Zona, tablero de envíos y totales.
 * Sustituye el Flujo 1 de n8n; las reglas (IVA 8 %, tope de $50,000, precio
 * atípico > 30 % contra la mediana de 3 meses) vienen de consolidar_insumos.py.
 */
final class SolicitudInsumoService
{
    public const IVA = 0.08;

    public const TOPE_FONDO = 50000.00;

    public const UMBRAL_PRECIO_ATIPICO = 0.30;

    public const MESES_HISTORIAL = 3;

    /**
     * Mes que se está solicitando: desde el día 15 se pide el mes siguiente
     * (misma regla que el nodo Config del Flujo 1).
     *
     * @return array{anio: int, mes: int}
     */
    public function mesObjetivo(?Carbon $hoy = null): array
    {
        $hoy ??= now();
        $objetivo = $hoy->day >= 15 ? $hoy->copy()->startOfMonth()->addMonth() : $hoy->copy()->startOfMonth();

        return ['anio' => $objetivo->year, 'mes' => $objetivo->month];
    }

    public function obtenerOCrear(Estacion $estacion, int $anio, int $mes, User $user): SolicitudInsumo
    {
        $solicitud = SolicitudInsumo::query()->firstOrCreate(
            ['estacion_id' => $estacion->id, 'anio' => $anio, 'mes' => $mes],
            ['estado' => SolicitudInsumo::BORRADOR, 'lugar_trabajo' => $estacion->nombre],
        );

        if ($solicitud->wasRecentlyCreated) {
            activity('solicitud_insumo')->performedOn($solicitud)->causedBy($user)->log('creada');
        }

        return $solicitud;
    }

    /**
     * Guarda la cabecera y reemplaza las partidas capturadas por la
     * estación. Conserva lo que ya completó la Zona en partidas que siguen
     * existiendo (mismo catálogo o misma descripción).
     *
     * @param  array<string, mixed>  $datos  Salida validada de GuardarSolicitudInsumoRequest.
     */
    public function guardar(SolicitudInsumo $solicitud, array $datos, User $user): SolicitudInsumo
    {
        return DB::transaction(function () use ($solicitud, $datos, $user) {
            $previas = $solicitud->partidas()->get()->keyBy(fn (SolicitudInsumoPartida $p) => mb_strtolower(trim($p->descripcion)));

            $solicitud->update(collect($datos)->only([
                'tipo_servicio', 'lugar_trabajo', 'justificacion_general', 'solicitante_nombre', 'solicitante_puesto',
            ])->all());

            $solicitud->partidas()->delete();

            foreach (array_values($datos['partidas']) as $indice => $fila) {
                $anterior = $previas->get(mb_strtolower(trim($fila['descripcion'])));
                $catalogo = isset($fila['catalogo_insumo_id']) ? CatalogoInsumo::query()->find($fila['catalogo_insumo_id']) : null;

                $solicitud->partidas()->create([
                    'numero' => $indice + 1,
                    'cantidad' => $fila['cantidad'],
                    'unidad' => $fila['unidad'],
                    'descripcion' => $fila['descripcion'],
                    'justificacion' => $fila['justificacion'] ?? null,
                    'catalogo_insumo_id' => $catalogo?->id,
                    'codigo' => $anterior?->codigo ?? $catalogo?->codigo,
                    'precio_unitario' => $anterior?->precio_unitario ?? $catalogo?->ultimo_precio,
                    'partida_presupuestal' => $anterior?->partida_presupuestal ?? $catalogo?->partida_presupuestal,
                    'proveedor' => $anterior?->proveedor ?? $catalogo?->proveedor,
                ]);
            }

            activity('solicitud_insumo')->performedOn($solicitud)->causedBy($user)
                ->withProperties(['partidas' => count($datos['partidas'])])->log('partidas guardadas');

            return $solicitud->refresh();
        });
    }

    public function enviar(SolicitudInsumo $solicitud, User $user): SolicitudInsumo
    {
        if ($solicitud->partidas()->doesntExist()) {
            throw ValidationException::withMessages([
                'partidas' => __('Captura al menos una partida antes de enviar.'),
            ]);
        }

        $solicitud->update([
            'estado' => SolicitudInsumo::ENVIADA,
            'enviada_at' => now(),
        ]);

        activity('solicitud_insumo')->performedOn($solicitud)->causedBy($user)->log('enviada');

        return $solicitud;
    }

    /**
     * Revisión de la Zona: guarda datos de precio/partida y aplica la
     * decisión (guardar | devolver | aprobar).
     *
     * @param  array<string, mixed>  $datos  Salida validada de RevisarSolicitudInsumoRequest.
     */
    public function revisar(SolicitudInsumo $solicitud, array $datos, User $user): SolicitudInsumo
    {
        return DB::transaction(function () use ($solicitud, $datos, $user) {
            foreach (($datos['partidas'] ?? []) as $id => $fila) {
                $partida = $solicitud->partidas()->whereKey($id)->first();

                $partida?->update([
                    'codigo' => $fila['codigo'] ?? null,
                    'precio_unitario' => $fila['precio_unitario'] ?? null,
                    'partida_presupuestal' => $fila['partida_presupuestal'] ?? null,
                    'proveedor' => $fila['proveedor'] ?? null,
                ]);
            }

            $accion = $datos['accion'];

            if ($accion === 'devolver') {
                $solicitud->update([
                    'estado' => SolicitudInsumo::OBSERVADA,
                    'comentario_zona' => $datos['comentario_zona'],
                    'revisada_por' => $user->id,
                    'revisada_at' => now(),
                ]);
            } elseif ($accion === 'aprobar') {
                $solicitud->update([
                    'estado' => SolicitudInsumo::APROBADA,
                    'comentario_zona' => $datos['comentario_zona'] ?? null,
                    'revisada_por' => $user->id,
                    'revisada_at' => now(),
                ]);
                $this->actualizarCatalogo($solicitud);
            }

            activity('solicitud_insumo')->performedOn($solicitud)->causedBy($user)
                ->withProperties(['accion' => $accion])->log('revisada: '.$accion);

            return $solicitud->refresh();
        });
    }

    /**
     * @return array{subtotal: float, iva: float, total: float, excedeTope: bool}
     */
    public function totales(SolicitudInsumo $solicitud): array
    {
        $subtotal = round($solicitud->partidas->sum(fn (SolicitudInsumoPartida $p) => $p->total() ?? 0.0), 2);
        $iva = round($subtotal * self::IVA, 2);
        $total = round($subtotal + $iva, 2);

        return ['subtotal' => $subtotal, 'iva' => $iva, 'total' => $total, 'excedeTope' => $total > self::TOPE_FONDO];
    }

    /**
     * Ids de partidas cuyo precio unitario se desvía más de 30 % de la
     * mediana histórica (últimos 3 meses de solicitudes aprobadas de la
     * misma descripción).
     *
     * @return list<int>
     */
    public function partidasAtipicas(SolicitudInsumo $solicitud): array
    {
        $desde = Carbon::create($solicitud->anio, $solicitud->mes, 1)->subMonths(self::MESES_HISTORIAL);

        $atipicas = [];

        foreach ($solicitud->partidas as $partida) {
            if ($partida->precio_unitario === null) {
                continue;
            }

            $historial = SolicitudInsumoPartida::query()
                ->whereHas('solicitud', fn ($q) => $q
                    ->where('estado', SolicitudInsumo::APROBADA)
                    ->where('id', '!=', $solicitud->id)
                    ->whereRaw('(anio * 12 + mes) >= ?', [$desde->year * 12 + $desde->month]))
                ->where('descripcion', $partida->descripcion)
                ->whereNotNull('precio_unitario')
                ->pluck('precio_unitario')
                ->map(fn ($precio) => (float) $precio);

            $mediana = $this->mediana($historial);

            if ($mediana > 0 && abs((float) $partida->precio_unitario - $mediana) / $mediana > self::UMBRAL_PRECIO_ATIPICO) {
                $atipicas[] = $partida->id;
            }
        }

        return $atipicas;
    }

    /**
     * Tablero de la Zona: una fila por estación con el estado de su
     * solicitud del mes (null = no ha iniciado).
     *
     * @return Collection<int, array{estacion: Estacion, solicitud: SolicitudInsumo|null}>
     */
    public function tablero(int $anio, int $mes): Collection
    {
        $solicitudes = SolicitudInsumo::query()
            ->where('anio', $anio)->where('mes', $mes)
            ->get()->keyBy('estacion_id');

        return Estacion::query()->where('is_operativa', true)->orderBy('orden')->get()
            ->map(fn (Estacion $estacion) => [
                'estacion' => $estacion,
                'solicitud' => $solicitudes->get($estacion->id),
            ]);
    }

    /**
     * Al aprobar, el último precio y la partida de cada insumo del catálogo
     * se actualizan con lo que acaba de validar la Zona.
     */
    private function actualizarCatalogo(SolicitudInsumo $solicitud): void
    {
        foreach ($solicitud->partidas()->whereNotNull('catalogo_insumo_id')->get() as $partida) {
            $partida->catalogo?->update(array_filter([
                'ultimo_precio' => $partida->precio_unitario,
                'codigo' => $partida->codigo,
                'partida_presupuestal' => $partida->partida_presupuestal,
                'proveedor' => $partida->proveedor,
            ], fn ($valor) => $valor !== null));
        }
    }

    /**
     * @param  Collection<int, float>  $valores
     */
    private function mediana(Collection $valores): float
    {
        if ($valores->isEmpty()) {
            return 0.0;
        }

        $ordenados = $valores->sort()->values();
        $mitad = intdiv($ordenados->count(), 2);

        return $ordenados->count() % 2 === 1
            ? $ordenados[$mitad]
            : ($ordenados[$mitad - 1] + $ordenados[$mitad]) / 2;
    }
}
