<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarFacturaRequest;
use App\Http\Requests\ConsultarFacturasRequest;
use App\Http\Requests\SubirFacturasRequest;
use App\Http\Requests\SubirPdfFacturaRequest;
use App\Http\Requests\VincularConceptoRequest;
use App\Models\Cotizacion;
use App\Models\Estacion;
use App\Models\Factura;
use App\Models\FacturaConcepto;
use App\Services\ComprobacionPdfService;
use App\Services\ComprobacionService;
use App\Services\ConsolidadoInsumosService;
use App\Services\CotizacionService;
use App\Services\FacturaService;
use App\Services\SolicitudInsumoService;
use App\Services\VerificacionSatService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Facturas CFDI del fondo rotatorio (fase B, antes el Flujo 2B de n8n): se
 * cargan desde el XML, se comparan con su cotización y con las partidas del
 * mes, y se corrigen a mano los vínculos que el sistema no pudo decidir. Las
 * cargan Jefe de Zona, Administrador y Edificio (FacturaPolicy). Controlador
 * delgado; la lógica vive en FacturaService y ComprobacionService.
 */
class FacturaController extends Controller
{
    public function __construct(
        private readonly FacturaService $service,
        private readonly ComprobacionService $comprobacion,
        private readonly SolicitudInsumoService $solicitudes,
    ) {}

    public function index(ConsultarFacturasRequest $request, ComprobacionPdfService $comprobacionPdf): View
    {
        $objetivo = $this->solicitudes->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);

        return view('insumos.facturas.index', [
            'anio' => $anio,
            'mes' => $mes,
            'meses' => ConsolidadoInsumosService::MESES,
            'firmantesComprobacion' => collect($comprobacionPdf->firmantes())->map(fn (array $f) => ['cargo' => $f['cargo'], 'nombre' => $f['nombre']])->all(),
            'resumen' => $this->comprobacion->resumen($anio, $mes),
            'facturas' => Factura::query()->with(['estacion', 'cotizacion'])->where('anio', $anio)->where('mes', $mes)->orderBy('fecha')->orderBy('id')->get(),
        ]);
    }

    /**
     * Carga uno o varios XML; cada archivo se procesa por separado y el
     * resultado de cada uno se muestra al regresar.
     */
    public function store(SubirFacturasRequest $request): RedirectResponse
    {
        $resultados = [];
        $pdfs = $this->service->emparejarPdf($request->file('xml'), $request->file('pdf'));

        foreach ($request->file('xml') as $i => $archivo) {
            if (! isset($pdfs[$i])) {
                $resultados[] = ['archivo' => $archivo->getClientOriginalName(), 'ok' => false, 'factura_id' => null, 'mensaje' => __('Falta el PDF de esta factura: debe llamarse igual que el XML (o sube un solo XML con un solo PDF).')];

                continue;
            }

            try {
                $factura = $this->service->importar($archivo, (int) $request->validated('anio'), (int) $request->validated('mes'), $request->user(), $pdfs[$i]);
                $resultados[] = ['archivo' => $archivo->getClientOriginalName(), 'ok' => true, 'factura_id' => $factura->id, 'mensaje' => $factura->referencia().' — '.$factura->estado];
            } catch (InvalidArgumentException $e) {
                $resultados[] = ['archivo' => $archivo->getClientOriginalName(), 'ok' => false, 'factura_id' => null, 'mensaje' => $e->getMessage()];
            }
        }

        $cargadas = count(array_filter($resultados, fn (array $r) => $r['ok']));

        return redirect()
            ->route('insumos.facturas.index', $request->safe()->only(['anio', 'mes']))
            ->with('status', trans_choice(':n factura cargada de :t archivo.|:n facturas cargadas de :t archivos.', $cargadas, ['n' => $cargadas, 't' => count($resultados)]))
            ->with('resultados_facturas', $resultados);
    }

    public function show(Factura $factura, CotizacionService $cotizaciones, ComprobacionPdfService $comprobacionPdf): View
    {
        $this->authorize('viewAny', Factura::class);

        $factura->load(['conceptos.partida.solicitud.estacion', 'conceptos.renglonCotizacion', 'hallazgos', 'cotizacion', 'estacion', 'creador']);

        return view('insumos.facturas.show', [
            'factura' => $factura,
            'vistaComprobacion' => [
                'datos' => $comprobacionPdf->datos($factura),
                'hojas' => collect([ComprobacionPdfService::FACTURA, ComprobacionPdfService::XML])->map(fn (string $tipo) => ['titulo' => $comprobacionPdf->titulo($tipo), 'texto' => $comprobacionPdf->texto($factura, $tipo)])->all(),
                'firmantes' => collect($comprobacionPdf->firmantes())->map(fn (array $f) => ['cargo' => $f['cargo'], 'nombre' => $f['nombre']])->all(),
                'tienePdf' => $factura->pdf_ruta !== null,
            ],
            'meses' => ConsolidadoInsumosService::MESES,
            'hallazgosPorConcepto' => $factura->hallazgos->groupBy(fn ($h) => $h->factura_concepto_id ?? 0),
            'cotizaciones' => Cotizacion::query()->where('anio', $factura->anio)->where('mes', $factura->mes)->orderBy('proveedor')->orderBy('folio')->get(),
            'estaciones' => Estacion::query()->orderBy('orden')->get(),
            'partidas' => $cotizaciones->partidasDelMes($factura->anio, $factura->mes)->map(fn ($p) => ['id' => $p->id, 'etiqueta' => $p->solicitud->estacion->nombre.' · '.$p->numero.'. '.$p->descripcion])->values(),
        ]);
    }

    public function update(Factura $factura, ActualizarFacturaRequest $request): RedirectResponse
    {
        $this->service->actualizarVinculos($factura, $request->integer('cotizacion_id') ?: null, $request->integer('estacion_id') ?: null);

        return redirect()->route('insumos.facturas.show', $factura)->with('status', __('Vínculos actualizados y factura analizada de nuevo.'));
    }

    public function vincularConcepto(FacturaConcepto $concepto, VincularConceptoRequest $request): RedirectResponse
    {
        $this->service->vincularConcepto($concepto, $request->integer('partida_id') ?: null);

        return redirect()->route('insumos.facturas.show', $concepto->factura_id)->with('status', __('Concepto vinculado y factura analizada de nuevo.'));
    }

    public function pdf(Factura $factura, SubirPdfFacturaRequest $request): RedirectResponse
    {
        $this->service->adjuntarPdf($factura, $request->file('archivo'));

        return redirect()->route('insumos.facturas.show', $factura)->with('status', __('PDF guardado.'));
    }

    /**
     * Consulta el estado de la factura en el SAT en este momento (el botón
     * "Verificar ahora"); la consulta automática corre en cola al cargar.
     */
    public function verificarSat(Factura $factura, VerificacionSatService $sat): RedirectResponse
    {
        $this->authorize('manage', Factura::class);

        $estado = $sat->verificar($factura);

        return redirect()->route('insumos.facturas.show', $factura)->with('status', __('Consulta al SAT: :estado.', ['estado' => $estado]));
    }

    public function destroy(Factura $factura): RedirectResponse
    {
        $this->authorize('manage', Factura::class);

        $anio = $factura->anio;
        $mes = $factura->mes;
        $this->service->eliminar($factura);

        return redirect()->route('insumos.facturas.index', ['anio' => $anio, 'mes' => $mes])->with('status', __('Factura eliminada.'));
    }

    /**
     * XML o PDF de la factura, solo para quien puede verla.
     */
    public function archivo(Factura $factura, string $tipo): StreamedResponse
    {
        $this->authorize('viewAny', Factura::class);
        abort_unless(in_array($tipo, ['xml', 'pdf'], true), 404);

        $ruta = $tipo === 'xml' ? $factura->xml_ruta : $factura->pdf_ruta;
        $nombre = $tipo === 'xml' ? $factura->xml_nombre : $factura->pdf_nombre;

        abort_if($ruta === null || ! Storage::disk('local')->exists($ruta), 404);

        return Storage::disk('local')->response($ruta, $nombre ?? 'factura.'.$tipo, [
            'Content-Type' => $tipo === 'xml' ? 'application/xml' : 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
