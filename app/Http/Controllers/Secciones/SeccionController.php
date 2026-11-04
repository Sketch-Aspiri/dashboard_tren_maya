<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\EstadoCuentaNube;
use App\Enums\TipoColumna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ListarSeccionRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Http\Requests\Secciones\StoreSeccionRequest;
use App\Http\Requests\Secciones\UpdateSeccionRequest;
use App\Models\Estacion;
use App\Models\NubeCredencial;
use App\Models\NubeCuenta;
use App\Models\Seccion;
use App\Models\SeccionImportacion;
use App\Models\SeccionMapeo;
use App\Services\Asistente\ModeloLenguaje;
use App\Services\Secciones\ConsultaSeccionService;
use App\Services\Secciones\ImportacionService;
use App\Services\Secciones\MenuService;
use App\Services\Secciones\NormalizadorValores;
use App\Services\Secciones\VisibilidadSecciones;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Pantalla de una sección (tabla + documentos + cargas) y su alta/edición
 * desde "Administrar menú".
 */
class SeccionController extends Controller
{
    public function __construct(
        private readonly MenuService $menu,
        private readonly ConsultaSeccionService $consulta,
        private readonly ImportacionService $importaciones,
        private readonly VisibilidadSecciones $visibilidad,
    ) {}

    public function show(ListarSeccionRequest $request, Seccion $seccion, NormalizadorValores $normalizador, ModeloLenguaje $claude): View
    {
        $this->authorize('view', $seccion);

        $seccion->load('grupo');
        $filtros = $request->filtros();
        $tabla = $request->tabla();

        // Sin las columnas JSON enormes (tablas, cambios) y ordenado solo por id: con
        // ellas MySQL falla con "Out of sort memory" (1038) en secciones con cargas grandes.
        $usuario = $request->user();
        $visibles = $this->visibilidad->idsVisiblesPara($usuario); // null = roles de zona: sin restricción
        $puedeGestionar = $usuario->can('gestionarDatos', $seccion);

        // Cargas y enlaces son de gestión: una estación ni los consulta.
        $cargas = ! $puedeGestionar ? collect() : $seccion->importaciones()
            ->select(['id', 'seccion_id', 'seccion_tabla_id', 'seccion_archivo_id', 'usuario_id', 'origen', 'estado', 'modo', 'resumen', 'aplicada_at', 'deshecha_at', 'created_at', 'updated_at'])
            ->with('usuario:id,name', 'archivo:id,nombre_original')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        // Las migas y subsecciones no revelan secciones no aprobadas para la estación.
        $esVisible = fn (Seccion $s): bool => $visibles === null || isset($visibles[$s->id]);
        $tabsPermitidas = $puedeGestionar ? ['tabla', 'documentos', 'enlaces', 'cargas'] : ['tabla', 'documentos'];
        $tabPedida = $request->validated('tab') ?? 'tabla';

        return view('secciones.show', [
            'seccion' => $seccion,
            // Todos los ancestros: los que la estación no puede abrir se muestran como texto (sin enlace), para saber de qué sección viene.
            'ancestros' => $seccion->ancestros(),
            'ancestrosAbribles' => array_values(array_map(fn (Seccion $a) => $a->id, array_filter($seccion->ancestros(), $esVisible))),
            'subsecciones' => $seccion->hijas()
                ->when($visibles !== null, fn ($q) => $q->whereIn('id', array_keys($visibles)))
                ->withCount([
                    'filas' => fn ($q) => $q->visiblePara($usuario),
                    'archivos' => fn ($q) => $q->visiblePara($usuario),
                    // Una estación no cuenta las subsecciones que no puede ver.
                    'hijas' => fn ($q) => $q->when($visibles !== null, fn ($q2) => $q2->whereIn('id', array_keys($visibles))),
                ])->get(),
            'puedeAnidar' => $seccion->nivel() < Seccion::PROFUNDIDAD_MAXIMA,
            'tablaActiva' => $tabla,
            // Una estación con permiso solo sobre algunas hojas ve solo esas pestañas.
            'tablas' => $seccion->tablas()
                ->when(($hojas = $this->visibilidad->tablasVisiblesEn($usuario, $seccion)) !== null, fn ($q) => $q->whereIn('id', $hojas))
                ->withCount(['filas' => fn ($q) => $q->visiblePara($usuario)])
                ->get(),
            'filtros' => $filtros,
            // Sin parte de tabla solo existe la pestaña de documentos.
            'tab' => $seccion->solo_documentos ? 'documentos' : (in_array($tabPedida, $tabsPermitidas, true) ? $tabPedida : 'tabla'),
            'filas' => $this->consulta->filas($tabla, $filtros, $request->user()),
            'documentos' => $this->consulta->documentos($seccion, $filtros['q'], $filtros['doc_orden'], $filtros['doc_tipo'], $request->user(), $filtros['carpeta']),
            // Navegador de carpetas de los documentos sincronizados; con búsqueda se ven todas las carpetas.
            'carpetaActual' => $filtros['carpeta'] ?? '',
            'migas' => $this->consulta->migas($filtros['carpeta']),
            'subcarpetas' => ($filtros['q'] ?? '') === '' ? $this->consulta->subcarpetas($seccion, $filtros['carpeta'], $request->user()) : [],
            'origenes' => $puedeGestionar ? $seccion->origenes()->with('cuenta:id,proveedor,cuenta_email,estado', 'mapeos.tabla')->orderBy('id')->get() : collect(),
            // Mapeo de la tabla espejo en pantalla (para el aviso de "revisar mapeo").
            'mapeoTabla' => $puedeGestionar && $tabla->esEspejo() ? SeccionMapeo::query()->where('seccion_tabla_id', $tabla->id)->first() : null,
            // Solo las cuentas que el usuario puede usar (NubeCuentaPolicy::usar); Edificio solo vincula enlaces públicos.
            'cuentasNube' => $puedeGestionar ? NubeCuenta::query()->activas()->orderBy('cuenta_email')->get(['id', 'proveedor', 'cuenta_email'])->filter(fn (NubeCuenta $cuenta): bool => $usuario->can('usar', $cuenta))->values() : collect(),
            'cuentasPorReconectar' => $puedeGestionar ? NubeCuenta::query()->where('estado', EstadoCuentaNube::RequiereReconexion->value)->orderBy('cuenta_email')->get(['id', 'proveedor', 'cuenta_email'])->filter(fn (NubeCuenta $cuenta): bool => $usuario->can('usar', $cuenta))->values() : collect(),
            'hayConexionesNube' => $puedeGestionar && NubeCredencial::query()->exists(),
            'tiposDocumento' => $this->consulta->tiposDeDocumento($seccion, $request->user()),
            'enlaces' => $usuario->can('verEnlaces', $seccion) ? $seccion->enlaces()->orderBy('titulo')->get() : collect(),
            'cargas' => $cargas,
            'cargaDeshacible' => $puedeGestionar ? $this->cargaDeshacible($seccion) : null,
            'cargaPendiente' => $cargas->first(fn ($carga) => $carga->isVistaPrevia()),
            'normalizador' => $normalizador,
            'tiposColumna' => TipoColumna::cases(),
            'iaDisponible' => $claude->disponible(),
            // Catálogo para el selector de estación y los permisos (solo roles de zona).
            'estaciones' => $request->user()->can('etiquetarEstacion', $seccion) ? Estacion::query()->orderBy('orden')->get(['id', 'nombre', 'is_operativa']) : collect(),
            // Nivel de la cuenta Estación en la hoja en pantalla (para el aviso de "solo lectura").
            'nivelHoja' => $this->visibilidad->nivelEnTabla($usuario, $tabla),
        ]);
    }

    /**
     * Solo la última carga aplicada es candidata; si no se puede deshacer
     * se muestra el motivo en lugar del botón.
     *
     * @return array{carga: SeccionImportacion, motivo: ?string}|null
     */
    private function cargaDeshacible(Seccion $seccion): ?array
    {
        $ultima = $this->importaciones->ultimaAplicada($seccion);

        return $ultima === null ? null : ['carga' => $ultima, 'motivo' => $this->importaciones->motivoParaNoDeshacer($ultima)];
    }

    public function store(StoreSeccionRequest $request): RedirectResponse
    {
        $this->authorize('create', Seccion::class);

        $seccion = $this->menu->crearSeccion($request->validated());

        return redirect()->route('secciones.show', $seccion)
            ->with('status', __('Sección ":nombre" creada. Arrastra un documento o define sus columnas para empezar.', ['nombre' => $seccion->nombre]));
    }

    public function update(UpdateSeccionRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->menu->actualizarSeccion($seccion, $request->validated());

        return back()->with('status', __('Sección actualizada.'));
    }

    public function mover(MoverElementoRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('update', $seccion);

        $this->menu->moverSeccion($seccion, $request->validated('direccion'));

        // Vuelve a la misma sección de la página, no al inicio.
        return redirect()->to(route('secciones.administrar').'#seccion-'.$seccion->id);
    }

    public function destroy(Request $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('delete', $seccion);

        $padre = $seccion->padre;
        $nombre = $seccion->nombre;
        $this->menu->eliminarSeccion($seccion);

        if ($request->boolean('desde_seccion') && $padre !== null) {
            return redirect()->route('secciones.show', $padre)
                ->with('status', __('Sección ":nombre" eliminada del menú.', ['nombre' => $nombre]));
        }

        return redirect()->route('secciones.administrar')
            ->with('status', __('Sección ":nombre" eliminada del menú.', ['nombre' => $nombre]));
    }
}
