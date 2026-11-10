<?php

namespace App\Services;

use App\Models\Cotizacion;
use App\Models\CotizacionRenglon;
use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Models\FacturaHallazgo;
use App\Models\SolicitudInsumoPartida;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Comprobación de facturas (fase B del fondo rotatorio, antes el Flujo 2B de
 * n8n). Todo se decide en código, sin IA: vincula cada factura con su
 * cotización (por las notas "COT - 196", por claves de artículo o por
 * contenido), alinea cada concepto con el renglón cotizado y la partida del
 * mes, y deja un hallazgo por cada diferencia: OK, ALERTA, ERROR o REVISAR.
 */
final class ComprobacionService
{
    /** Similitud mínima (%) para emparejar dos descripciones. */
    private const SIMILITUD_MINIMA = 60;

    /** Similitud por debajo de la cual una misma clave con otra descripción es sospechosa. */
    private const SIMILITUD_DESCRIPCION = 45;

    /** Porcentaje del subtotal por encima del cual una retención de ISR parece incoherente. */
    private const ISR_MAXIMO = 0.10;

    public function __construct(
        private readonly ProveedorService $proveedores,
        private readonly CotizacionService $cotizaciones,
    ) {}

    /**
     * Recalcula vínculos y hallazgos de una factura. Respeta lo que una
     * persona vinculó a mano (cotización y conceptos).
     */
    public function analizar(Factura $factura): void
    {
        DB::transaction(function () use ($factura) {
            $factura->hallazgos()->delete();
            $factura->load('conceptos');

            $this->vincularCotizacion($factura);
            $factura->load('cotizacion.renglones.partida', 'conceptos');

            $this->vincularConceptos($factura);
            $factura->load('conceptos.partida.solicitud.estacion', 'conceptos.renglonCotizacion');

            $this->asignarEstacion($factura);

            $hallazgos = [...$this->validarComprobante($factura), ...$this->compararConceptos($factura)];

            foreach ($hallazgos as $hallazgo) {
                FacturaHallazgo::query()->create($hallazgo + ['factura_id' => $factura->id]);
            }

            $factura->estado = $this->peor(array_column($hallazgos, 'tipo'));
            $factura->save();
        });
    }

    // --- Vínculos -----------------------------------------------------------------

    private function vincularCotizacion(Factura $factura): void
    {
        if ($factura->vinculo_cotizacion === 'manual') {
            return;
        }

        $candidatas = Cotizacion::query()->with('renglones')->where('anio', $factura->anio)->where('mes', $factura->mes)->get();
        $encontrada = null;
        $como = null;

        if ($factura->referencia_cot !== null) {
            $porNumero = $candidatas->filter(fn (Cotizacion $c) => (ltrim((string) preg_replace('/\D/', '', $c->folio), '0') ?: '0') === $factura->referencia_cot);
            $encontrada = $porNumero->first(fn (Cotizacion $c) => $this->mismoProveedor($c, $factura)) ?? $porNumero->first();
            $como = $encontrada ? 'notas' : null;
        }

        if ($encontrada === null) {
            [$encontrada, $como] = $this->vincularPorContenido($factura, $candidatas);
        }

        $factura->update(['cotizacion_id' => $encontrada?->id, 'vinculo_cotizacion' => $como]);
    }

    /**
     * Sin notas: por claves de artículo iguales y, si tampoco, por parecido
     * del contenido (este último queda con confianza baja).
     *
     * @param  Collection<int, Cotizacion>  $candidatas
     * @return array{0: Cotizacion|null, 1: string|null}
     */
    private function vincularPorContenido(Factura $factura, Collection $candidatas): array
    {
        $conceptos = $factura->conceptos;
        $total = max(1, $conceptos->count());
        $mejor = [null, null, 0.0];

        foreach ($candidatas as $cotizacion) {
            $claves = $cotizacion->renglones->pluck('clave')->filter()->map(fn ($c) => mb_strtolower(trim($c)))->all();
            $coinciden = $conceptos->filter(fn (FacturaConcepto $c) => $c->no_identificacion && in_array(mb_strtolower(trim($c->no_identificacion)), $claves, true))->count();

            if ($coinciden >= 1 && $coinciden / $total >= 0.5 && $coinciden > $mejor[2]) {
                $mejor = [$cotizacion, 'claves', (float) $coinciden];
            }
        }

        if ($mejor[0] !== null) {
            return [$mejor[0], $mejor[1]];
        }

        foreach ($candidatas as $cotizacion) {
            $parecidos = $conceptos->filter(fn (FacturaConcepto $c) => $this->mejorRenglon($c->descripcion, $cotizacion->renglones) !== null)->count();

            if ($parecidos / $total >= 0.5 && $parecidos > $mejor[2]) {
                $mejor = [$cotizacion, 'contenido', (float) $parecidos];
            }
        }

        return [$mejor[0], $mejor[1]];
    }

    private function vincularConceptos(Factura $factura): void
    {
        $cotizacion = $factura->cotizacion;
        $partidas = $this->cotizaciones->partidasDelMes($factura->anio, $factura->mes);

        foreach ($factura->conceptos as $concepto) {
            if ($concepto->vinculo === 'manual') {
                continue;
            }

            $renglon = null;
            $vinculo = null;

            if ($cotizacion !== null) {
                $renglon = $cotizacion->renglones->first(fn (CotizacionRenglon $r) => $r->clave && $concepto->no_identificacion && strcasecmp(trim($r->clave), trim($concepto->no_identificacion)) === 0);
                $vinculo = $renglon ? 'clave' : null;

                if ($renglon === null && ($renglon = $this->mejorRenglon($concepto->descripcion, $cotizacion->renglones)) !== null) {
                    $vinculo = 'texto';
                }
            }

            $partidaId = $renglon?->solicitud_insumo_partida_id;

            if ($renglon === null && ($partida = $this->mejorPartida($concepto->descripcion, $partidas)) !== null) {
                $partidaId = $partida->id;
                $vinculo = 'documento';
            }

            $concepto->update(['cotizacion_renglon_id' => $renglon?->id, 'solicitud_insumo_partida_id' => $partidaId, 'vinculo' => $vinculo]);
        }
    }

    /**
     * La estación de la factura es la de la mayoría de sus partidas vinculadas.
     */
    private function asignarEstacion(Factura $factura): void
    {
        if ($factura->estacion_id !== null) {
            return;
        }

        $porEstacion = $factura->conceptos->map(fn (FacturaConcepto $c) => $c->partida?->solicitud->estacion_id)->filter()->countBy();

        if ($porEstacion->isEmpty() && $factura->cotizacion !== null) {
            $porEstacion = $factura->cotizacion->renglones->map(fn (CotizacionRenglon $r) => $r->partida?->solicitud->estacion_id)->filter()->countBy();
        }

        if ($porEstacion->isNotEmpty()) {
            $factura->update(['estacion_id' => $porEstacion->sortDesc()->keys()->first()]);
        }
    }

    // --- Validaciones del comprobante ----------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function validarComprobante(Factura $factura): array
    {
        $h = [];
        $tolerancia = (float) config('insumos.tolerancia_importe');
        $receptor = (string) config('insumos.rfc_receptor');

        if (strcasecmp($factura->receptor_rfc, $receptor) !== 0) {
            $h[] = $this->hallazgo('ERROR', 'RECEPTOR_DISTINTO', 'El receptor de la factura es '.$factura->receptor_rfc.' y debe ser '.$receptor.'.');
        }

        if ($factura->tipo_comprobante !== 'I') {
            $h[] = $this->hallazgo('ERROR', 'TIPO_NO_SOPORTADO', 'El comprobante es de tipo '.$factura->tipo_comprobante.'; solo se comprueban facturas de ingreso (I).');
        }

        match ($factura->verificacion_sat) {
            'CANCELADO' => $h[] = $this->hallazgo('ERROR', 'CANCELADA_SAT', 'El SAT reporta esta factura como CANCELADA: no debe comprobarse ni pagarse.'),
            'NO_ENCONTRADO' => $h[] = $this->hallazgo('REVISAR', 'NO_ENCONTRADA_SAT', 'El SAT no encontró esta factura con su UUID, RFC y total; revisa que el XML sea el original.'),
            'ERROR' => $h[] = $this->hallazgo('ALERTA', 'SAT_NO_DISPONIBLE', 'No se pudo consultar el SAT ('.($factura->sat_detalle ?: 'sin respuesta').'); se reintentará.'),
            default => null,
        };

        if ($factura->moneda !== 'MXN') {
            $h[] = $this->hallazgo('ALERTA', 'MONEDA_DISTINTA', 'La factura está en '.$factura->moneda.', no en pesos mexicanos.');
        }

        $proveedor = $this->proveedores->buscar($factura->emisor_rfc, $factura->cotizacion?->proveedor ?? $factura->emisor_nombre);

        if ($proveedor !== null && blank($proveedor->rfc)) {
            $proveedor->update(['rfc' => $factura->emisor_rfc]);
        }

        $esperada = $proveedor?->iva ?? (int) config('insumos.iva_defecto');

        if ($proveedor === null) {
            $h[] = $this->hallazgo('ALERTA', 'PROVEEDOR_NO_REGISTRADO', 'El emisor '.$factura->emisor_nombre.' ('.$factura->emisor_rfc.') no está en Proveedores e IVA; se esperó IVA de '.$esperada.' %.');
        } elseif ($proveedor->iva === null) {
            $h[] = $this->hallazgo('ALERTA', 'IVA_NO_CONFIGURADO', 'El proveedor '.$proveedor->nombre.' no tiene IVA definido; se esperó '.$esperada.' %.');
        }

        $erroresIva = 0;

        foreach ($factura->conceptos as $concepto) {
            if (abs((float) ($concepto->iva_tasa ?? 0) - $esperada) > 0.01) {
                $erroresIva++;
                $h[] = $this->hallazgo('ERROR', 'IVA_NO_CORRESPONDE', 'Concepto '.$concepto->numero.': lleva IVA de '.($concepto->iva_tasa ?? 0).' % y el proveedor factura al '.$esperada.' %.', $concepto->id);
            }
        }

        $baseNeta = (float) $factura->subtotal - (float) $factura->descuento;

        if ($erroresIva === 0 && abs((float) $factura->iva - round($baseNeta * $esperada / 100, 2)) > $tolerancia) {
            $h[] = $this->hallazgo('ERROR', 'IVA_TOTAL_NO_CUADRA', 'El IVA de la factura ($'.number_format((float) $factura->iva, 2).') no es el '.$esperada.' % de $'.number_format($baseNeta, 2).'.', null, round($baseNeta * $esperada / 100, 2), (float) $factura->iva);
        }

        $this->validarIsr($factura, $proveedor?->retiene_isr ?? false, $h);

        $sumaConceptos = (float) $factura->conceptos->sum('importe');

        if (abs($sumaConceptos - (float) $factura->subtotal) > $tolerancia) {
            $h[] = $this->hallazgo('ERROR', 'SUMA_NO_CUADRA', 'La suma de los conceptos ($'.number_format($sumaConceptos, 2).') no coincide con el subtotal ($'.number_format((float) $factura->subtotal, 2).').', null, (float) $factura->subtotal, $sumaConceptos);
        }

        $total = $baseNeta + (float) $factura->iva - (float) $factura->isr_retenido - (float) $factura->otros_retenidos;

        if (abs($total - (float) $factura->total) > $tolerancia) {
            $h[] = $this->hallazgo('ERROR', 'TOTAL_NO_CUADRA', 'El total ($'.number_format((float) $factura->total, 2).') no es subtotal + IVA − retenciones ($'.number_format($total, 2).').', null, round($total, 2), (float) $factura->total);
        }

        if ($factura->estacion_id === null) {
            $h[] = $this->hallazgo('REVISAR', 'ESTACION_NO_IDENTIFICADA', 'No se pudo saber a qué estación corresponde la factura; asígnala a mano.');
        }

        if ($factura->cotizacion_id === null) {
            $h[] = $this->hallazgo('REVISAR', 'FACTURA_SIN_COTIZACION', 'No se encontró la cotización de esta factura ni por notas, claves o contenido.');
        } elseif ($factura->vinculo_cotizacion === 'contenido') {
            $h[] = $this->hallazgo('REVISAR', 'VINCULO_CONFIANZA_BAJA', 'La cotización se vinculó solo por parecido de contenido; confírmala.');
        }

        return $h;
    }

    /**
     * @param  list<array<string, mixed>>  $h
     */
    private function validarIsr(Factura $factura, bool $retiene, array &$h): void
    {
        $isr = (float) $factura->isr_retenido;

        if ($retiene && $isr <= 0) {
            $h[] = $this->hallazgo('ALERTA', 'ISR_FALTANTE', 'El proveedor retiene ISR (persona física) pero la factura no trae retención.');
        } elseif ($retiene && $isr > (float) $factura->subtotal * self::ISR_MAXIMO) {
            $h[] = $this->hallazgo('ALERTA', 'ISR_INCOHERENTE', 'La retención de ISR ($'.number_format($isr, 2).') es mayor al 10 % del subtotal.');
        } elseif (! $retiene && $isr > 0) {
            $h[] = $this->hallazgo('ALERTA', 'ISR_INESPERADO', 'La factura trae ISR retenido ($'.number_format($isr, 2).') y el proveedor no está marcado como persona física.');
        }
    }

    // --- Comparación concepto por concepto ----------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function compararConceptos(Factura $factura): array
    {
        $h = [];

        foreach ($factura->conceptos as $concepto) {
            $renglon = $concepto->renglonCotizacion;
            $partida = $concepto->partida;

            if ($renglon === null && $partida === null) {
                $h[] = $this->hallazgo('REVISAR', 'RENGLON_SIN_EQUIVALENTE', 'Concepto '.$concepto->numero.' ('.Str::limit($concepto->descripcion, 60).'): no tiene equivalente en la cotización ni en el documento.', $concepto->id);

                continue;
            }

            $propios = $this->compararConcepto($concepto, $renglon, $partida);
            array_push($h, ...($propios ?: [$this->hallazgo('OK', 'CONCEPTO_OK', 'Concepto '.$concepto->numero.': coincide con '.($renglon ? 'la cotización' : 'el documento').'.', $concepto->id)]));
        }

        return $h;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function compararConcepto(FacturaConcepto $concepto, ?CotizacionRenglon $renglon, ?SolicitudInsumoPartida $partida): array
    {
        $h = [];
        $n = $concepto->numero;
        $cantidadRef = (float) ($renglon?->cantidad ?? $partida->cantidad);
        $cantidad = (float) $concepto->cantidad;

        if (abs($cantidad - $cantidadRef) > 0.0001) {
            $h[] = $this->hallazgo('ERROR', 'CANTIDAD_DISTINTA', 'Concepto '.$n.': se facturaron '.$cantidad.' y se '.($renglon ? 'cotizaron ' : 'solicitaron ').$cantidadRef.'.', $concepto->id, $cantidadRef, $cantidad);
        }

        // Contra el documento solo se compara el precio si la fase A ya lo actualizó con una cotización.
        $precioRef = $renglon?->precio_unitario !== null ? (float) $renglon->precio_unitario : ($renglon === null && $partida?->precio_estimado !== null ? (float) $partida->precio_unitario : null);

        if ($precioRef !== null) {
            $diferencia = (float) $concepto->valor_unitario - $precioRef;
            $pct = $precioRef > 0 ? abs($diferencia) / $precioRef : ($diferencia == 0.0 ? 0.0 : 1.0);

            if (abs($diferencia) > (float) config('insumos.tolerancia_redondeo')) {
                $tipo = $pct > (float) config('insumos.tolerancia_precio_alerta') ? 'ERROR' : 'ALERTA';
                $h[] = $this->hallazgo($tipo, 'PRECIO_DISTINTO', 'Concepto '.$n.': precio facturado $'.number_format((float) $concepto->valor_unitario, 2).' contra $'.number_format($precioRef, 2).' ('.round($pct * 100, 1).' %).', $concepto->id, $precioRef, (float) $concepto->valor_unitario);
            }
        }

        if ($renglon?->importe !== null && abs((float) $concepto->importe - (float) $renglon->importe) > (float) config('insumos.tolerancia_importe')) {
            $h[] = $this->hallazgo('ALERTA', 'IMPORTE_DISTINTO', 'Concepto '.$n.': importe facturado $'.number_format((float) $concepto->importe, 2).' contra cotizado $'.number_format((float) $renglon->importe, 2).'.', $concepto->id, (float) $renglon->importe, (float) $concepto->importe);
        }

        $referencia = $renglon?->descripcion ?? $partida?->descripcion;

        if ($concepto->vinculo === 'clave' && $referencia !== null && $this->similitud($concepto->descripcion, $referencia) < self::SIMILITUD_DESCRIPCION) {
            $h[] = $this->hallazgo('ALERTA', 'DESCRIPCION_DISTINTA', 'Concepto '.$n.': misma clave pero otra descripción ('.Str::limit($concepto->descripcion, 50).' contra '.Str::limit($referencia, 50).'); posible cambio de marca o presentación.', $concepto->id);
        }

        return $h;
    }

    // --- Resúmenes del mes ---------------------------------------------------------------

    /**
     * @return array{facturas: int, total: float, fondo: float, pendiente: float, excede: bool, hallazgos: array<string, int>, facturas_por_estado: array<string, int>, no_facturado: float, sat: string}
     */
    public function resumen(int $anio, int $mes): array
    {
        $facturas = Factura::query()->where('anio', $anio)->where('mes', $mes)->get();
        $total = round((float) $facturas->sum('total'), 2);
        $fondo = (float) config('insumos.fondo_zona');
        $conteo = FacturaHallazgo::query()->whereIn('factura_id', $facturas->pluck('id'))->selectRaw('tipo, count(*) as n')->groupBy('tipo')->pluck('n', 'tipo');

        return [
            'facturas' => $facturas->count(),
            'total' => $total,
            'fondo' => $fondo,
            'pendiente' => round($fondo - $total, 2),
            'excede' => $total > $fondo,
            'hallazgos' => collect(Factura::GRAVEDAD)->mapWithKeys(fn ($t) => [$t => (int) ($conteo[$t] ?? 0)])->all(),
            'facturas_por_estado' => collect(Factura::GRAVEDAD)->mapWithKeys(fn ($t) => [$t => $facturas->where('estado', $t)->count()])->all(),
            'no_facturado' => round((float) collect($this->noFacturado($anio, $mes))->sum('importe'), 2),
            'sat' => $this->resumenSat($facturas),
        ];
    }

    /**
     * Estado del mes frente al SAT: CANCELADO si alguna lo está, luego
     * NO_ENCONTRADO, ERROR, NO_VERIFICADO (alguna sin consultar) o VIGENTE.
     *
     * @param  Collection<int, Factura>  $facturas
     */
    private function resumenSat(Collection $facturas): string
    {
        $estados = $facturas->pluck('verificacion_sat');

        foreach (['CANCELADO', 'NO_ENCONTRADO', 'ERROR', 'NO_VERIFICADO'] as $estado) {
            if ($estados->contains($estado)) {
                return $estado;
            }
        }

        return $facturas->isEmpty() ? 'NO_VERIFICADO' : 'VIGENTE';
    }

    /**
     * Lo pedido o cotizado que no se compró, con el motivo.
     *
     * @return list<array{estacion: string, estacion_orden: int, descripcion: string, cantidad: float, unidad: string, precio: float|null, importe: float, justificacion: string|null, proveedor: string|null, motivo: string}>
     */
    public function noFacturado(int $anio, int $mes): array
    {
        $partidas = $this->cotizaciones->partidasDelMes($anio, $mes);
        $facturadas = FacturaConcepto::query()->whereHas('factura', fn ($q) => $q->where('anio', $anio)->where('mes', $mes))->pluck('solicitud_insumo_partida_id')->filter()->unique()->all();
        $renglonesFacturados = FacturaConcepto::query()->whereHas('factura', fn ($q) => $q->where('anio', $anio)->where('mes', $mes))->pluck('cotizacion_renglon_id')->filter()->unique()->all();
        $renglones = CotizacionRenglon::query()->with('cotizacion')->whereHas('cotizacion', fn ($q) => $q->where('anio', $anio)->where('mes', $mes))->get();
        $porPartida = $renglones->whereNotNull('solicitud_insumo_partida_id')->groupBy('solicitud_insumo_partida_id');
        $filas = [];

        foreach ($partidas as $partida) {
            if (in_array($partida->id, $facturadas, true)) {
                continue;
            }

            $cot = $porPartida->get($partida->id)?->first();
            $filas[] = [
                'estacion' => $partida->solicitud->estacion->nombre, 'estacion_orden' => (int) $partida->solicitud->estacion->orden,
                'descripcion' => $partida->descripcion, 'cantidad' => (float) $partida->cantidad, 'unidad' => $partida->unidad,
                'precio' => $partida->precio_unitario === null ? null : (float) $partida->precio_unitario,
                'importe' => round((float) $partida->cantidad * (float) ($partida->precio_unitario ?? 0), 2),
                'justificacion' => $partida->justificacion, 'proveedor' => $partida->proveedor,
                'motivo' => $cot ? 'Cotizado (cot. '.$cot->cotizacion->folio.') y no facturado' : 'Sin cotización ni factura',
            ];
        }

        foreach ($renglones->whereNull('solicitud_insumo_partida_id') as $renglon) {
            if (! in_array($renglon->id, $renglonesFacturados, true)) {
                $filas[] = [
                    'estacion' => 'Fuera del documento', 'estacion_orden' => 999, 'descripcion' => $renglon->descripcion, 'cantidad' => (float) $renglon->cantidad, 'unidad' => '',
                    'precio' => $renglon->precio_unitario === null ? null : (float) $renglon->precio_unitario,
                    'importe' => round((float) $renglon->cantidad * (float) ($renglon->precio_unitario ?? 0), 2),
                    'justificacion' => null, 'proveedor' => $renglon->cotizacion->proveedor, 'motivo' => 'Cotizado fuera del documento (cot. '.$renglon->cotizacion->folio.')',
                ];
            }
        }

        usort($filas, fn (array $a, array $b) => [$a['estacion_orden'], $a['descripcion']] <=> [$b['estacion_orden'], $b['descripcion']]);

        return $filas;
    }

    // --- Utilidades -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function hallazgo(string $tipo, string $codigo, string $detalle, ?int $conceptoId = null, ?float $cotizado = null, ?float $facturado = null): array
    {
        return [
            'factura_concepto_id' => $conceptoId,
            'tipo' => $tipo,
            'codigo' => $codigo,
            'detalle' => $detalle,
            'valor_cotizado' => $cotizado,
            'valor_facturado' => $facturado,
            'diferencia' => $cotizado !== null && $facturado !== null ? round($facturado - $cotizado, 4) : null,
        ];
    }

    /**
     * @param  list<string>  $tipos
     */
    private function peor(array $tipos): string
    {
        foreach (Factura::GRAVEDAD as $tipo) {
            if (in_array($tipo, $tipos, true)) {
                return $tipo;
            }
        }

        return Factura::OK;
    }

    private function mismoProveedor(Cotizacion $cotizacion, Factura $factura): bool
    {
        $a = ProveedorService::clave($cotizacion->proveedor);
        $b = ProveedorService::clave($factura->emisor_nombre);

        return $a !== '' && $b !== '' && (str_contains($b, $a) || str_contains($a, $b));
    }

    /**
     * @param  Collection<int, CotizacionRenglon>  $renglones
     */
    private function mejorRenglon(string $descripcion, Collection $renglones): ?CotizacionRenglon
    {
        return $this->mejor($descripcion, $renglones, fn (CotizacionRenglon $r) => $r->descripcion);
    }

    /**
     * @param  Collection<int, SolicitudInsumoPartida>  $partidas
     */
    private function mejorPartida(string $descripcion, Collection $partidas): ?SolicitudInsumoPartida
    {
        return $this->mejor($descripcion, $partidas, fn (SolicitudInsumoPartida $p) => $p->descripcion);
    }

    /**
     * @template T
     *
     * @param  Collection<int, T>  $candidatos
     * @param  callable(T): string  $texto
     * @return T|null
     */
    private function mejor(string $descripcion, Collection $candidatos, callable $texto): mixed
    {
        $mejor = null;
        $mejorPuntaje = 0.0;

        foreach ($candidatos as $candidato) {
            $puntaje = $this->similitud($descripcion, $texto($candidato));

            if ($puntaje > $mejorPuntaje) {
                $mejorPuntaje = $puntaje;
                $mejor = $candidato;
            }
        }

        return $mejorPuntaje >= self::SIMILITUD_MINIMA ? $mejor : null;
    }

    private function similitud(string $a, string $b): float
    {
        similar_text($this->normalizar($a), $this->normalizar($b), $porcentaje);

        return $porcentaje;
    }

    private function normalizar(string $texto): string
    {
        return Str::ascii(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $texto))));
    }
}
