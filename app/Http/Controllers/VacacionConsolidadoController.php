<?php

namespace App\Http\Controllers;

use App\Models\VacacionPeriodo;
use App\Services\Vacaciones\VacacionConsolidadoLibro;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Descarga el Excel del rol vigente (GENERAL, una hoja por estación y CAMBIOS). Solo la Zona.
 */
class VacacionConsolidadoController extends Controller
{
    public function __construct(private readonly VacacionConsolidadoLibro $libro) {}

    public function descargar(Request $request, VacacionPeriodo $periodo): BinaryFileResponse
    {
        $this->authorize('gestionar', VacacionPeriodo::class);

        $ruta = tempnam(sys_get_temp_dir(), 'vac');
        $this->libro->generar($periodo, $ruta);

        activity('vacaciones')->performedOn($periodo)->causedBy($request->user())->log('consolidado de vacaciones descargado');

        return response()->download($ruta, sprintf('Rol de vacaciones %d v%d.xlsx', $periodo->anio, $periodo->version), [
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
    }
}
