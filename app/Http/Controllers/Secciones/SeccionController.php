<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\TipoColumna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ListarSeccionRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Http\Requests\Secciones\StoreSeccionRequest;
use App\Http\Requests\Secciones\UpdateSeccionRequest;
use App\Models\Seccion;
use App\Models\SeccionImportacion;
use App\Services\Asistente\ModeloLenguaje;
use App\Services\Secciones\ConsultaSeccionService;
use App\Services\Secciones\ImportacionService;
use App\Services\Secciones\MenuService;
use App\Services\Secciones\NormalizadorValores;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

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
    ) {}

    public function show(ListarSeccionRequest $request, Seccion $seccion, NormalizadorValores $normalizador, ModeloLenguaje $claude): View
    {
        $this->authorize('view', $seccion);

        $seccion->load('grupo');
        $filtros = $request->filtros();
        $tabla = $request->tabla();

        // Sin las columnas JSON enormes (tablas, cambios) y ordenado solo por id: con
        // ellas MySQL falla con "Out of sort memory" (1038) en secciones con cargas grandes.
        $cargas = $seccion->importaciones()
            ->select(['id', 'seccion_id', 'seccion_tabla_id', 'seccion_archivo_id', 'usuario_id', 'origen', 'estado', 'modo', 'resumen', 'aplicada_at', 'deshecha_at', 'created_at', 'updated_at'])
            ->with('usuario:id,name', 'archivo:id,nombre_original')
            ->orderByDesc('id')
            ->limit(30)
            ->get();

        return view('secciones.show', [
            'seccion' => $seccion,
            'tablaActiva' => $tabla,
            'tablas' => $seccion->tablas()->withCount('filas')->get(),
            'filtros' => $filtros,
            'tab' => $request->validated('tab') ?? 'tabla',
            'filas' => $this->consulta->filas($tabla, $filtros),
            'documentos' => $this->consulta->documentos($seccion, $filtros['q']),
            'cargas' => $cargas,
            'cargaDeshacible' => $this->cargaDeshacible($seccion),
            'cargaPendiente' => $cargas->first(fn ($carga) => $carga->isVistaPrevia()),
            'normalizador' => $normalizador,
            'tiposColumna' => TipoColumna::cases(),
            'iaDisponible' => $claude->disponible(),
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

    public function destroy(Seccion $seccion): RedirectResponse
    {
        $this->authorize('delete', $seccion);

        $this->menu->eliminarSeccion($seccion);

        return redirect()->route('secciones.administrar')
            ->with('status', __('Sección ":nombre" eliminada del menú.', ['nombre' => $seccion->nombre]));
    }
}
