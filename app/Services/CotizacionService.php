<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Models\CotizacionRenglon;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Models\SolicitudInsumoPartida;
use App\Models\User;
use App\Services\Ecosistema\AvisosEcosistema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cotizaciones de proveedores (fase A del fondo rotatorio, antes el Flujo 2A
 * de n8n). Cada renglón cotizado se vincula con una partida de las
 * solicitudes del mes. Su precio reemplaza al estimado SOLO si se cumplen
 * todas las reglas: la cotización cuadra en sus números, es el mismo
 * producto, la cantidad coincide, trae precio y queda dentro de ±25 % del
 * estimado. Si falla una, el renglón queda en REVISAR con el motivo y no se
 * toca la partida; la jefatura puede aceptarlo o rechazarlo a mano.
 */
final class CotizacionService
{
    /** Variación máxima del precio cotizado contra el estimado. */
    public const TOLERANCIA_PRECIO = 0.25;

    /** Diferencia máxima en pesos al validar la aritmética de una cotización. */
    public const TOLERANCIA_PESOS = 0.10;

    /** Similitud mínima (%) para sugerir una partida a un renglón cotizado. */
    private const SIMILITUD_MINIMA = 55;

    public function __construct(
        private readonly ProveedorService $proveedores,
        private readonly ConsolidadoInsumosService $consolidado,
        private readonly AvisosEcosistema $avisos,
    ) {}

    /**
     * Partidas vigentes de las solicitudes aprobadas del mes: lo que se puede
     * cotizar.
     *
     * @return Collection<int, SolicitudInsumoPartida>
     */
    public function partidasDelMes(int $anio, int $mes): Collection
    {
        return SolicitudInsumoPartida::query()
            ->with('solicitud.estacion')
            ->where('eliminada_por_coordinacion', false)
            ->whereHas('solicitud', fn ($q) => $q->where('anio', $anio)->where('mes', $mes)->whereIn('estado', SolicitudInsumo::ESTADOS_APROBADOS))
            ->get()
            ->sortBy(fn (SolicitudInsumoPartida $p) => ($p->solicitud->estacion->orden ?? 0) * 1000 + $p->numero)
            ->values();
    }

    /**
     * Crea o reemplaza una cotización con sus renglones y aplica las reglas.
     *
     * @param  array<string, mixed>  $datos  Salida validada de GuardarCotizacionRequest.
     */
    public function guardar(?Cotizacion $cotizacion, array $datos, ?UploadedFile $archivo, User $usuario, bool $leidaConIa = false): Cotizacion
    {
        $validacionAnterior = $cotizacion?->validacion;

        $guardada = DB::transaction(function () use ($cotizacion, $datos, $archivo, $usuario, $leidaConIa) {
            if ($cotizacion !== null) {
                $this->revertirTodos($cotizacion);
            }

            $cotizacion ??= new Cotizacion(['creada_por' => $usuario->id]);
            $proveedor = trim((string) preg_replace('/\s+/u', ' ', $datos['proveedor']));
            [$cuadra, $detalle] = $this->validarAritmetica($datos, $proveedor);

            $cotizacion->fill([
                'anio' => $datos['anio'],
                'mes' => $datos['mes'],
                'proveedor' => $proveedor,
                'folio' => trim($datos['folio']),
                'fecha' => $datos['fecha'] ?? null,
                'subtotal' => $datos['subtotal'] ?? null,
                'iva' => $datos['iva'] ?? null,
                'total' => $datos['total'] ?? null,
                'validacion' => $cuadra ? Cotizacion::OK : Cotizacion::REVISAR,
                'validacion_detalle' => $detalle,
                'leida_con_ia' => $cotizacion->leida_con_ia || $leidaConIa,
            ]);

            if ($archivo !== null) {
                $this->borrarArchivo($cotizacion);
                $cotizacion->archivo_ruta = $archivo->store('cotizaciones', 'local');
                $cotizacion->archivo_nombre = mb_substr(basename($archivo->getClientOriginalName()), 0, 255);
            }

            $cotizacion->save();
            $cotizacion->renglones()->delete();
            $this->proveedores->registrar($proveedor);

            foreach (array_values($datos['renglones']) as $i => $fila) {
                $renglon = $cotizacion->renglones()->create([
                    'solicitud_insumo_partida_id' => $fila['partida_id'] ?? null,
                    'numero' => $i + 1,
                    'clave' => $fila['clave'] ?? null,
                    'descripcion' => $fila['descripcion'],
                    'cantidad' => $fila['cantidad'],
                    'precio_unitario' => $fila['precio_unitario'] ?? null,
                    'importe' => $fila['importe'] ?? null,
                    'coincidencia' => $fila['coincidencia'] ?? 'mismo',
                ]);

                $this->evaluar($renglon, $cotizacion);
            }

            return $cotizacion->load('renglones.partida');
        });

        // Aviso al Jefe de Zona solo cuando la cotización PASA a quedar por revisar (no en cada guardado).
        if ($guardada->validacion === Cotizacion::REVISAR && $validacionAnterior !== Cotizacion::REVISAR) {
            $this->avisos->cotizacionPorRevisar($guardada, $usuario);
        }

        return $guardada;
    }

    /**
     * Elimina la cotización devolviendo a cada partida su precio estimado.
     */
    public function eliminar(Cotizacion $cotizacion): void
    {
        DB::transaction(function () use ($cotizacion) {
            $this->revertirTodos($cotizacion);
            $this->borrarArchivo($cotizacion);
            $cotizacion->delete();
        });
    }

    /**
     * Acepta a mano un renglón en REVISAR: su precio pasa a la partida.
     */
    public function aceptar(CotizacionRenglon $renglon): void
    {
        if ($renglon->partida === null || $renglon->precio_unitario === null) {
            throw ValidationException::withMessages([
                'renglon' => __('Para aceptar el precio el renglón debe estar vinculado con una partida y traer precio.'),
            ]);
        }

        DB::transaction(function () use ($renglon) {
            $this->aplicar($renglon);
            $renglon->update(['estatus' => CotizacionRenglon::ACTUALIZADO, 'motivo' => 'ACEPTADO_MANUALMENTE', 'motivo_detalle' => $renglon->motivo_detalle]);
        });

        activity('cotizacion')->performedOn($renglon->cotizacion)->withProperties(['renglon' => $renglon->numero])->log('renglon aceptado a mano');
    }

    /**
     * Rechaza el precio de un renglón: la partida vuelve a su precio anterior.
     */
    public function rechazar(CotizacionRenglon $renglon): void
    {
        DB::transaction(function () use ($renglon) {
            $this->revertirRenglon($renglon);
            $renglon->update(['estatus' => CotizacionRenglon::REVISAR, 'motivo' => 'RECHAZADO_MANUALMENTE']);
        });

        activity('cotizacion')->performedOn($renglon->cotizacion)->withProperties(['renglon' => $renglon->numero])->log('renglon rechazado a mano');
    }

    /**
     * Aplica las reglas a un renglón y, si todas se cumplen, actualiza la
     * partida.
     */
    private function evaluar(CotizacionRenglon $renglon, Cotizacion $cotizacion): void
    {
        $partida = $renglon->partida;

        if ($partida === null) {
            $renglon->update(['estatus' => CotizacionRenglon::SIN_VINCULAR, 'motivo' => 'FUERA_DEL_DOCUMENTO']);

            return;
        }

        [$motivo, $detalle] = $this->motivoDeRevision($renglon, $partida, $cotizacion);

        if ($motivo !== null) {
            $renglon->update(['estatus' => CotizacionRenglon::REVISAR, 'motivo' => $motivo, 'motivo_detalle' => $detalle]);

            return;
        }

        $this->aplicar($renglon);
        $renglon->update(['estatus' => CotizacionRenglon::ACTUALIZADO, 'motivo' => null, 'motivo_detalle' => null]);
    }

    /**
     * @return array{0: string|null, 1: string|null} Motivo (null = cumple todo) y detalle.
     */
    private function motivoDeRevision(CotizacionRenglon $renglon, SolicitudInsumoPartida $partida, Cotizacion $cotizacion): array
    {
        if ($cotizacion->validacion === Cotizacion::REVISAR) {
            return ['COTIZACION_CON_PROBLEMAS', $cotizacion->validacion_detalle];
        }

        if ($renglon->precio_unitario === null) {
            return ['SIN_PRECIO', null];
        }

        if ($renglon->coincidencia === 'otra_marca') {
            return ['CAMBIO_PRODUCTO_MARCA', 'La cotización ofrece otra marca, modelo, medida o presentación.'];
        }

        if ($renglon->coincidencia === 'distinto') {
            return ['EMPAREJAMIENTO_DUDOSO', 'Se marcó como un producto distinto al solicitado.'];
        }

        if (round((float) $renglon->cantidad, 2) !== round((float) $partida->cantidad, 2)) {
            return ['CANTIDAD_DISTINTA', 'Cotizado '.(float) $renglon->cantidad.' y solicitado '.(float) $partida->cantidad.'.'];
        }

        $estimado = (float) ($partida->precio_estimado ?? $partida->precio_unitario ?? 0);

        if ($estimado > 0) {
            $variacion = ((float) $renglon->precio_unitario - $estimado) / $estimado;

            if (abs($variacion) > self::TOLERANCIA_PRECIO) {
                return ['PRECIO_FUERA_DE_RANGO', 'Estimado $'.number_format($estimado, 2).' y cotizado $'.number_format((float) $renglon->precio_unitario, 2).' ('.round($variacion * 100, 1).' %).'];
            }
        }

        return [null, null];
    }

    /**
     * El precio cotizado pasa a la partida; el estimado se conserva la primera
     * vez (si otra cotización ya lo cambió, el estimado original ya está).
     */
    private function aplicar(CotizacionRenglon $renglon): void
    {
        $partida = $renglon->partida;

        $yaCotizada = CotizacionRenglon::query()
            ->where('solicitud_insumo_partida_id', $partida->id)
            ->where('estatus', CotizacionRenglon::ACTUALIZADO)
            ->where('id', '!=', $renglon->id)
            ->exists();

        if (! $yaCotizada) {
            $partida->precio_estimado = $partida->precio_unitario;
        }

        $partida->precio_unitario = $renglon->precio_unitario;
        $partida->save();
    }

    /**
     * Deshace el efecto de un renglón actualizado: si otra cotización también
     * actualizó la partida, queda con ese precio; si no, vuelve al estimado.
     */
    private function revertirRenglon(CotizacionRenglon $renglon): void
    {
        if ($renglon->estatus !== CotizacionRenglon::ACTUALIZADO || $renglon->partida === null) {
            return;
        }

        $partida = $renglon->partida;
        $otro = CotizacionRenglon::query()
            ->where('solicitud_insumo_partida_id', $partida->id)
            ->where('estatus', CotizacionRenglon::ACTUALIZADO)
            ->where('id', '!=', $renglon->id)
            ->latest('id')->first();

        if ($otro !== null) {
            $partida->precio_unitario = $otro->precio_unitario;
        } else {
            $partida->precio_unitario = $partida->precio_estimado;
            $partida->precio_estimado = null;
        }

        $partida->save();
    }

    private function revertirTodos(Cotizacion $cotizacion): void
    {
        foreach ($cotizacion->renglones()->with('partida')->get() as $renglon) {
            $this->revertirRenglon($renglon);
        }
    }

    private function borrarArchivo(Cotizacion $cotizacion): void
    {
        if ($cotizacion->archivo_ruta !== null) {
            Storage::disk('local')->delete($cotizacion->archivo_ruta);
            $cotizacion->archivo_ruta = null;
            $cotizacion->archivo_nombre = null;
        }
    }

    /**
     * Verifica en código que la cotización cuadre: importe de cada renglón,
     * suma contra subtotal, IVA contra la tasa del proveedor y total.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: bool, 1: string|null}
     */
    public function validarAritmetica(array $datos, string $proveedor): array
    {
        $problemas = [];
        $suma = 0.0;

        foreach (array_values($datos['renglones']) as $i => $fila) {
            $calculado = isset($fila['precio_unitario']) ? round((float) $fila['cantidad'] * (float) $fila['precio_unitario'], 2) : null;

            if ($calculado !== null && isset($fila['importe']) && abs($calculado - (float) $fila['importe']) > self::TOLERANCIA_PESOS) {
                $problemas[] = 'Renglón '.($i + 1).': cantidad × precio ($'.number_format($calculado, 2).') no coincide con el importe ($'.number_format((float) $fila['importe'], 2).').';
            }

            $suma += isset($fila['importe']) ? (float) $fila['importe'] : (float) $calculado;
        }

        $subtotal = $datos['subtotal'] ?? null;
        $iva = $datos['iva'] ?? null;
        $total = $datos['total'] ?? null;
        $retieneIsr = (bool) Proveedor::query()->where('nombre', $proveedor)->value('retiene_isr');

        if ($subtotal !== null && abs($suma - (float) $subtotal) > self::TOLERANCIA_PESOS) {
            $problemas[] = 'La suma de los importes ($'.number_format($suma, 2).') no coincide con el subtotal ($'.number_format((float) $subtotal, 2).').';
        }

        if ($subtotal !== null && $iva !== null) {
            $tasa = $this->proveedores->tasaAplicable($proveedor);
            $esperado = round((float) $subtotal * $tasa / 100, 2);

            if (abs($esperado - (float) $iva) > self::TOLERANCIA_PESOS) {
                $problemas[] = 'El IVA ($'.number_format((float) $iva, 2).') no es el '.$tasa.' % del subtotal ($'.number_format($esperado, 2).').';
            }
        }

        if (! $retieneIsr && $subtotal !== null && $iva !== null && $total !== null
            && abs((float) $subtotal + (float) $iva - (float) $total) > self::TOLERANCIA_PESOS) {
            $problemas[] = 'El total ($'.number_format((float) $total, 2).') no es subtotal + IVA ($'.number_format((float) $subtotal + (float) $iva, 2).').';
        }

        return [$problemas === [], $problemas === [] ? null : implode(' ', $problemas)];
    }

    /**
     * Sugiere la partida más parecida a cada renglón cotizado (por texto y
     * cantidad); solo es una ayuda, la persona confirma.
     *
     * @param  list<array{descripcion: string, cantidad?: float|null}>  $renglones
     * @param  Collection<int, SolicitudInsumoPartida>  $partidas
     * @return list<int|null> ID de partida sugerida por renglón.
     */
    public function sugerirPartidas(array $renglones, Collection $partidas): array
    {
        return array_map(function (array $renglon) use ($partidas) {
            $mejor = null;
            $mejorPuntaje = 0.0;
            $texto = $this->normalizar($renglon['descripcion']);

            foreach ($partidas as $partida) {
                similar_text($texto, $this->normalizar($partida->descripcion), $porcentaje);

                if ($porcentaje > $mejorPuntaje) {
                    $mejorPuntaje = $porcentaje;
                    $mejor = $partida->id;
                }
            }

            return $mejorPuntaje >= self::SIMILITUD_MINIMA ? $mejor : null;
        }, $renglones);
    }

    /**
     * Resumen del mes: cuántas partidas quedaron actualizadas, en revisión y
     * sin cotización, y el total real contra el fondo.
     *
     * @return array{partidas: int, actualizadas: int, en_revision: int, sin_cotizacion: int, fuera_del_documento: int, cotizaciones: int, con_problemas: int, subtotal_estimado: float, subtotal_real: float, total_con_iva: float, fondo: float, excede: bool}
     */
    public function resumen(int $anio, int $mes): array
    {
        $partidas = $this->partidasDelMes($anio, $mes);
        $renglones = $this->renglonesDelMes($anio, $mes);
        $porPartida = $renglones->whereNotNull('solicitud_insumo_partida_id')->groupBy('solicitud_insumo_partida_id');

        $actualizadas = $partidas->filter(fn ($p) => $porPartida->get($p->id)?->contains('estatus', CotizacionRenglon::ACTUALIZADO))->count();
        $enRevision = $partidas->filter(fn ($p) => $porPartida->has($p->id) && ! $porPartida->get($p->id)->contains('estatus', CotizacionRenglon::ACTUALIZADO))->count();
        $datos = $this->consolidado->datos($anio, $mes);

        return [
            'partidas' => $partidas->count(),
            'actualizadas' => $actualizadas,
            'en_revision' => $enRevision,
            'sin_cotizacion' => $partidas->count() - $actualizadas - $enRevision,
            'fuera_del_documento' => $renglones->whereNull('solicitud_insumo_partida_id')->count(),
            'cotizaciones' => Cotizacion::query()->where('anio', $anio)->where('mes', $mes)->count(),
            'con_problemas' => Cotizacion::query()->where('anio', $anio)->where('mes', $mes)->where('validacion', Cotizacion::REVISAR)->count(),
            'subtotal_estimado' => round($partidas->sum(fn ($p) => (float) $p->cantidad * (float) ($p->precio_estimado ?? $p->precio_unitario ?? 0)), 2),
            'subtotal_real' => round($partidas->sum(fn ($p) => (float) $p->cantidad * (float) ($p->precio_unitario ?? 0)), 2),
            'total_con_iva' => $datos['total'],
            'fondo' => $datos['tope'],
            'excede' => $datos['total'] > $datos['tope'],
        ];
    }

    /**
     * Datos para el reporte de Excel y para las columnas de cotización del
     * ANEXO con precios reales.
     *
     * @return array{
     *     actualizados: list<array<string, mixed>>, revisar: list<array<string, mixed>>, fuera: list<array<string, mixed>>,
     *     sin_cotizacion: list<array<string, mixed>>, cotizaciones: list<array<string, mixed>>, anotaciones: array<int, array<string, mixed>>
     * }
     */
    public function reporte(int $anio, int $mes): array
    {
        $partidas = $this->partidasDelMes($anio, $mes);
        $renglones = $this->renglonesDelMes($anio, $mes)->load('cotizacion', 'partida.solicitud.estacion');
        $porPartida = $renglones->whereNotNull('solicitud_insumo_partida_id')->groupBy('solicitud_insumo_partida_id');

        $actualizados = [];
        $revisar = [];
        $fuera = [];
        $anotaciones = [];

        foreach ($renglones as $r) {
            $estacion = $r->partida?->solicitud->estacion->nombre;
            $cot = $r->cotizacion->proveedor.' '.$r->cotizacion->folio;

            if ($r->estatus === CotizacionRenglon::ACTUALIZADO) {
                $estimado = (float) ($r->partida->precio_estimado ?? 0);
                $actualizados[] = [
                    'estacion' => $estacion, 'descripcion' => $r->partida->descripcion, 'estimado' => $estimado, 'cotizado' => (float) $r->precio_unitario,
                    'variacion' => $estimado > 0 ? round(((float) $r->precio_unitario - $estimado) / $estimado * 100, 1) : null, 'cotizacion' => $cot,
                ];
            } elseif ($r->estatus === CotizacionRenglon::REVISAR) {
                $revisar[] = ['estacion' => $estacion, 'descripcion' => $r->partida?->descripcion ?? $r->descripcion, 'tipo' => $r->motivo, 'detalle' => $r->motivo_detalle, 'cotizacion' => $cot];
            } else {
                $fuera[] = ['descripcion' => $r->descripcion, 'cantidad' => (float) $r->cantidad, 'precio' => $r->precio_unitario === null ? null : (float) $r->precio_unitario, 'cotizacion' => $cot];
            }
        }

        $sinCotizacion = [];

        foreach ($partidas as $p) {
            $lista = $porPartida->get($p->id);
            $nota = ['estimado' => $p->precio_estimado === null ? (float) $p->precio_unitario : (float) $p->precio_estimado];

            if ($lista === null) {
                $sinCotizacion[] = ['estacion' => $p->solicitud->estacion->nombre, 'descripcion' => $p->descripcion, 'cantidad' => (float) $p->cantidad];
                $anotaciones[$p->id] = $nota + ['cotizacion' => null, 'estatus' => 'SIN COTIZACION', 'observacion' => null];

                continue;
            }

            $ganador = $lista->firstWhere('estatus', CotizacionRenglon::ACTUALIZADO) ?? $lista->first();
            $anotaciones[$p->id] = $nota + [
                'cotizacion' => $ganador->cotizacion->proveedor.' '.$ganador->cotizacion->folio,
                'estatus' => $ganador->estatus === CotizacionRenglon::ACTUALIZADO ? 'ACTUALIZADO' : 'REVISAR',
                'observacion' => $ganador->motivo.($ganador->motivo_detalle ? ': '.$ganador->motivo_detalle : ''),
            ];
        }

        $cotizaciones = Cotizacion::query()->withCount('renglones')->where('anio', $anio)->where('mes', $mes)->orderBy('proveedor')->orderBy('folio')->get()
            ->map(fn (Cotizacion $c) => [
                'proveedor' => $c->proveedor, 'folio' => $c->folio, 'fecha' => $c->fecha?->format('d/m/Y'), 'renglones' => $c->renglones_count,
                'subtotal' => $c->subtotal === null ? null : (float) $c->subtotal, 'iva' => $c->iva === null ? null : (float) $c->iva,
                'total' => $c->total === null ? null : (float) $c->total, 'validacion' => $c->validacion, 'detalle' => $c->validacion_detalle,
            ])->all();

        return compact('actualizados', 'revisar', 'fuera') + ['sin_cotizacion' => $sinCotizacion, 'cotizaciones' => $cotizaciones, 'anotaciones' => $anotaciones];
    }

    /**
     * @return Collection<int, CotizacionRenglon>
     */
    private function renglonesDelMes(int $anio, int $mes): Collection
    {
        return CotizacionRenglon::query()
            ->with('partida')
            ->whereHas('cotizacion', fn ($q) => $q->where('anio', $anio)->where('mes', $mes))
            ->get();
    }

    private function normalizar(string $texto): string
    {
        return Str::ascii(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto))));
    }
}
