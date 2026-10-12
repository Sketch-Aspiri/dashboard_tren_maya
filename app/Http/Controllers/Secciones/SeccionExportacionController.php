<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ListarSeccionRequest;
use App\Models\Seccion;
use App\Services\Secciones\ExportacionService;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Descarga la tabla de una sección en Excel (respeta la búsqueda activa).
 */
class SeccionExportacionController extends Controller
{
    public function __invoke(ListarSeccionRequest $request, Seccion $seccion, ExportacionService $exportacion): StreamedResponse
    {
        $this->authorize('view', $seccion);

        activity('seccion')
            ->causedBy($request->user())
            ->performedOn($seccion)
            ->event('exportacion_excel')
            ->withProperties(['q' => $request->validated('q')])
            ->log('Exportación de la tabla de la sección a Excel.');

        return $exportacion->descargar($seccion, $request->filtros());
    }
}
