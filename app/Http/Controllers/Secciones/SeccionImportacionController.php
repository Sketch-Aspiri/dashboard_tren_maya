<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\ModoImportacion;
use App\Enums\TipoColumna;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\OpcionesImportacionRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionImportacion;
use App\Services\Secciones\ExtractorIa;
use App\Services\Secciones\ImportacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vista previa, aplicación, deshacer y descarte de un documento con tabla
 * arrastrado a una sección; y la extracción con IA de documentos que no se
 * pudieron leer sin ella.
 */
class SeccionImportacionController extends Controller
{
    public function __construct(private readonly ImportacionService $importaciones) {}

    public function show(Request $request, Seccion $seccion, SeccionImportacion $importacion): View|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        if (! $importacion->isVistaPrevia()) {
            return redirect()->route('secciones.show', [$seccion, 'tab' => 'cargas']);
        }

        $tabla = max(0, (int) $request->query('tabla', 0));
        $filaEncabezado = $request->filled('fila_encabezado') ? max(0, (int) $request->query('fila_encabezado')) : null;

        return view('secciones.importacion', [
            'seccion' => $seccion->load('grupo', 'columnas'),
            'importacion' => $importacion->load('archivo'),
            'propuesta' => $this->importaciones->propuesta($importacion, $tabla, $filaEncabezado),
            'tiposColumna' => TipoColumna::cases(),
            'modos' => ModoImportacion::cases(),
        ]);
    }

    public function calcular(OpcionesImportacionRequest $request, Seccion $seccion, SeccionImportacion $importacion): JsonResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        return response()->json([
            'success' => true,
            'data' => $this->importaciones->calcular($importacion, $request->opciones()),
            'error' => null,
            'meta' => null,
        ]);
    }

    public function aplicar(OpcionesImportacionRequest $request, Seccion $seccion, SeccionImportacion $importacion): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $importacion = $this->importaciones->aplicar($importacion, $request->opciones(), $request->user());
        $resumen = $importacion->resumen;

        return redirect()->route('secciones.show', $seccion)->with('status', __(
            'Carga aplicada: :nuevas nuevas, :actualizadas actualizadas, :eliminadas eliminadas. Puedes deshacerla desde la pestaña "Cargas".',
            ['nuevas' => $resumen['nuevas'], 'actualizadas' => $resumen['actualizadas'], 'eliminadas' => $resumen['eliminadas']],
        ));
    }

    public function deshacer(Request $request, Seccion $seccion, SeccionImportacion $importacion): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->importaciones->deshacer($importacion, $request->user());

        return redirect()->route('secciones.show', [$seccion, 'tab' => 'cargas'])->with('status', __('Carga deshecha: la tabla volvió a como estaba antes.'));
    }

    public function destroy(Seccion $seccion, SeccionImportacion $importacion): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->importaciones->descartar($importacion);

        return redirect()->route('secciones.show', $seccion)->with('status', __('Carga descartada. El documento sigue guardado en "Documentos".'));
    }

    public function extraerConIa(Request $request, Seccion $seccion, SeccionArchivo $archivo, ExtractorIa $extractor): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $tablas = $extractor->extraerTablas($archivo, $request->user());
        $importacion = $this->importaciones->desdeTablas($seccion, $archivo, $tablas, SeccionImportacion::ORIGEN_IA, $request->user());

        return redirect()->route('secciones.importaciones.show', [$seccion, $importacion])
            ->with('status', __('La IA encontró :n tabla(s). Revísalas antes de aplicarlas.', ['n' => count($tablas)]));
    }
}
