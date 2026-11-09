<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarSolicitudesInsumosRequest;
use App\Http\Requests\GuardarSolicitudInsumoRequest;
use App\Http\Requests\RevisarSolicitudInsumoRequest;
use App\Models\CatalogoInsumo;
use App\Models\Estacion;
use App\Models\Proveedor;
use App\Models\SolicitudInsumo;
use App\Services\ConsolidadoInsumosService;
use App\Services\ProveedorService;
use App\Services\SolicitudInsumoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Recursos materiales" -> Solicitud de insumos (fondo rotatorio). La
 * estación captura y envía; la Zona revisa, completa precios y aprueba.
 * Controlador delgado: la lógica vive en SolicitudInsumoService y el acceso
 * por estación en SolicitudInsumoPolicy.
 */
class SolicitudInsumoController extends Controller
{
    public function __construct(
        private readonly SolicitudInsumoService $service,
        private readonly ConsolidadoInsumosService $consolidado,
        private readonly ProveedorService $proveedores,
    ) {}

    /**
     * Zona: tablero de envíos del mes. Estación: va directo a su solicitud.
     */
    public function index(ConsultarSolicitudesInsumosRequest $request): View|RedirectResponse
    {
        $objetivo = $this->service->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);
        $usuario = $request->user();

        if ($usuario->hasRole('Estación')) {
            $estacion = Estacion::query()->findOrFail($usuario->estacion_id);

            return redirect()->route('insumos.estacion', ['estacion' => $estacion->id, 'anio' => $anio, 'mes' => $mes]);
        }

        return view('insumos.index', [
            'anio' => $anio,
            'mes' => $mes,
            'tablero' => $this->service->tablero($anio, $mes),
            'puedeConsolidar' => $usuario->can('consolidate', SolicitudInsumo::class),
            'consolidado' => $usuario->can('consolidate', SolicitudInsumo::class)
                ? $this->consolidado->datos($anio, $mes)
                : null,
        ]);
    }

    /**
     * Abre (o crea en borrador) la solicitud de una estación para un mes.
     */
    public function estacion(Estacion $estacion, ConsultarSolicitudesInsumosRequest $request): RedirectResponse
    {
        $this->authorize('manageFor', [SolicitudInsumo::class, $estacion]);

        $objetivo = $this->service->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);

        $solicitud = $this->service->obtenerOCrear($estacion, $anio, $mes, $request->user());

        return redirect()->route('insumos.show', $solicitud);
    }

    public function show(SolicitudInsumo $solicitud, Request $request): View
    {
        $this->authorize('view', $solicitud);

        $solicitud->load(['estacion', 'partidas.catalogo', 'revisor']);
        $tasas = $this->proveedores->tasas();

        return view('insumos.show', [
            'solicitud' => $solicitud,
            'totales' => $this->service->totales($solicitud),
            'atipicas' => $this->service->partidasAtipicas($solicitud),
            'puedeEditar' => $request->user()->can('update', $solicitud),
            'puedeRevisar' => $request->user()->can('review', $solicitud),
            'limite' => (float) config('insumos.limite_estacion'),
            // Precio e IVA estimados de cada insumo del catálogo, para calcular al
            // vuelo cuánto presupuesto le queda a la estación mientras captura.
            'catalogo' => CatalogoInsumo::query()->where('activo', true)->orderBy('descripcion')
                ->get(['id', 'descripcion', 'unidad', 'ultimo_precio', 'proveedor'])
                ->map(fn (CatalogoInsumo $c) => [
                    'id' => $c->id,
                    'descripcion' => $c->descripcion,
                    'unidad' => $c->unidad,
                    'precio' => $c->ultimo_precio === null ? null : (float) $c->ultimo_precio,
                    'iva' => $this->proveedores->tasaAplicable($c->proveedor, $tasas),
                ]),
            'ivaPorPartida' => $solicitud->partidas->mapWithKeys(fn ($p) => [$p->id => $this->proveedores->tasaAplicable($p->proveedor ?? $p->catalogo?->proveedor, $tasas)]),
            'proveedores' => Proveedor::query()->orderBy('nombre')->pluck('nombre'),
        ]);
    }

    public function update(SolicitudInsumo $solicitud, GuardarSolicitudInsumoRequest $request): RedirectResponse
    {
        $this->service->guardar($solicitud, $request->validated(), $request->user());

        return redirect()
            ->route('insumos.show', $solicitud)
            ->with('status', __('Solicitud guardada correctamente.'));
    }

    public function enviar(SolicitudInsumo $solicitud, Request $request): RedirectResponse
    {
        $this->authorize('update', $solicitud);

        $this->service->enviar($solicitud, $request->user());

        return redirect()
            ->route('insumos.show', $solicitud)
            ->with('status', __('Solicitud enviada a la Zona para revisión.'));
    }

    public function revisar(SolicitudInsumo $solicitud, RevisarSolicitudInsumoRequest $request): RedirectResponse
    {
        $this->service->revisar($solicitud, $request->validated(), $request->user());

        return redirect()
            ->route('insumos.show', $solicitud)
            ->with('status', __('Revisión guardada.'));
    }

    /**
     * Autocompletado de la captura (JSON interno, mismo auth que la web).
     */
    public function catalogo(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SolicitudInsumo::class);

        $termino = trim((string) $request->query('q', ''));

        $items = CatalogoInsumo::query()
            ->where('activo', true)
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', '%'.addcslashes($termino, '%_\\').'%'))
            ->orderBy('descripcion')
            ->limit(20)
            ->get(['id', 'descripcion', 'unidad']);

        return response()->json(['success' => true, 'data' => $items, 'error' => null, 'meta' => null]);
    }
}
