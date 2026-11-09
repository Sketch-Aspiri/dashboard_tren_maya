<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarCotizacionesRequest;
use App\Http\Requests\GuardarCotizacionRequest;
use App\Models\Cotizacion;
use App\Models\CotizacionRenglon;
use App\Models\Proveedor;
use App\Services\ConsolidadoInsumosService;
use App\Services\CotizacionService;
use App\Services\LectorCotizacionIa;
use App\Services\SolicitudInsumoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cotizaciones del fondo rotatorio (fase A, antes el Flujo 2A de n8n): se
 * capturan sobre las partidas aprobadas del mes y, si cumplen las reglas, su
 * precio reemplaza al estimado. Las capturan Jefe de Zona, Administrador y
 * Edificio (CotizacionPolicy). Controlador delgado; la lógica vive en
 * CotizacionService.
 */
class CotizacionController extends Controller
{
    public function __construct(
        private readonly CotizacionService $service,
        private readonly SolicitudInsumoService $solicitudes,
        private readonly LectorCotizacionIa $lector,
    ) {}

    public function index(ConsultarCotizacionesRequest $request): View
    {
        [$anio, $mes] = $this->mes($request);

        return view('insumos.cotizaciones.index', [
            'anio' => $anio,
            'mes' => $mes,
            'meses' => ConsolidadoInsumosService::MESES,
            'resumen' => $this->service->resumen($anio, $mes),
            'cotizaciones' => Cotizacion::query()->withCount('renglones')->where('anio', $anio)->where('mes', $mes)->orderBy('proveedor')->orderBy('folio')->get(),
        ]);
    }

    public function create(ConsultarCotizacionesRequest $request): View
    {
        [$anio, $mes] = $this->mes($request);

        return $this->formulario(null, $anio, $mes);
    }

    public function store(GuardarCotizacionRequest $request): RedirectResponse
    {
        $cotizacion = $this->service->guardar(null, $request->validated(), $request->file('archivo'), $request->user(), $request->boolean('leida_con_ia'));

        return redirect()->route('insumos.cotizaciones.show', $cotizacion)->with('status', __('Cotización guardada.'));
    }

    public function show(Cotizacion $cotizacion): View
    {
        $this->authorize('viewAny', Cotizacion::class);

        $cotizacion->load(['renglones.partida.solicitud.estacion', 'creador']);

        return view('insumos.cotizaciones.show', ['cotizacion' => $cotizacion, 'meses' => ConsolidadoInsumosService::MESES]);
    }

    public function edit(Cotizacion $cotizacion): View
    {
        $this->authorize('manage', Cotizacion::class);

        return $this->formulario($cotizacion->load('renglones'), $cotizacion->anio, $cotizacion->mes);
    }

    public function update(Cotizacion $cotizacion, GuardarCotizacionRequest $request): RedirectResponse
    {
        $cotizacion = $this->service->guardar($cotizacion, $request->validated(), $request->file('archivo'), $request->user(), $request->boolean('leida_con_ia'));

        return redirect()->route('insumos.cotizaciones.show', $cotizacion)->with('status', __('Cotización actualizada.'));
    }

    public function destroy(Cotizacion $cotizacion): RedirectResponse
    {
        $this->authorize('manage', Cotizacion::class);

        $anio = $cotizacion->anio;
        $mes = $cotizacion->mes;
        $this->service->eliminar($cotizacion);

        return redirect()->route('insumos.cotizaciones.index', ['anio' => $anio, 'mes' => $mes])->with('status', __('Cotización eliminada; las partidas volvieron a su precio estimado.'));
    }

    public function aceptar(CotizacionRenglon $renglon): RedirectResponse
    {
        $this->authorize('manage', Cotizacion::class);

        $this->service->aceptar($renglon);

        return redirect()->route('insumos.cotizaciones.show', $renglon->cotizacion_id)->with('status', __('Precio aceptado y aplicado a la partida.'));
    }

    public function rechazar(CotizacionRenglon $renglon): RedirectResponse
    {
        $this->authorize('manage', Cotizacion::class);

        $this->service->rechazar($renglon);

        return redirect()->route('insumos.cotizaciones.show', $renglon->cotizacion_id)->with('status', __('Precio rechazado; la partida volvió a su precio anterior.'));
    }

    /**
     * PDF original de la cotización, solo para quien puede verla.
     */
    public function archivo(Cotizacion $cotizacion): StreamedResponse
    {
        $this->authorize('viewAny', Cotizacion::class);

        abort_if($cotizacion->archivo_ruta === null || ! Storage::disk('local')->exists($cotizacion->archivo_ruta), 404);

        return Storage::disk('local')->response($cotizacion->archivo_ruta, $cotizacion->archivo_nombre ?? 'cotizacion.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function mes(ConsultarCotizacionesRequest $request): array
    {
        $objetivo = $this->solicitudes->mesObjetivo();

        return [(int) ($request->validated('anio') ?? $objetivo['anio']), (int) ($request->validated('mes') ?? $objetivo['mes'])];
    }

    private function formulario(?Cotizacion $cotizacion, int $anio, int $mes): View
    {
        $this->authorize('manage', Cotizacion::class);

        return view('insumos.cotizaciones.form', [
            'cotizacion' => $cotizacion,
            'anio' => $anio,
            'mes' => $mes,
            'meses' => ConsolidadoInsumosService::MESES,
            'partidas' => $this->service->partidasDelMes($anio, $mes)->map(fn ($p) => [
                'id' => $p->id,
                'etiqueta' => $p->solicitud->estacion->nombre.' · '.$p->numero.'. '.$p->descripcion,
                'cantidad' => (float) $p->cantidad,
                'precio' => $p->precio_estimado !== null ? (float) $p->precio_estimado : ($p->precio_unitario !== null ? (float) $p->precio_unitario : null),
            ])->values(),
            'proveedores' => Proveedor::query()->orderBy('nombre')->pluck('nombre'),
            'iaDisponible' => $this->lector->disponible(),
        ]);
    }
}
