<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ListarSeccionRequest;
use App\Models\Seccion;
use App\Services\Secciones\ExportacionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga una tabla de la sección en Excel (?tabla=; respeta la búsqueda
 * activa). Una tabla por exportación.
 */
class SeccionExportacionController extends Controller
{
    public function __invoke(ListarSeccionRequest $request, Seccion $seccion, ExportacionService $exportacion): StreamedResponse
    {
        $this->authorize('view', $seccion);

        $tabla = $request->tabla();

        activity('seccion')
            ->causedBy($request->user())
            ->performedOn($seccion)
            ->event('exportacion_excel')
            ->withProperties(['q' => $request->validated('q'), 'tabla_id' => $tabla->id])
            ->log('Exportación de una tabla de la sección a Excel.');

        return $exportacion->descargar($tabla, $request->filtros());
    }
}
