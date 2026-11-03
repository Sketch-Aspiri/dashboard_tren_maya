<?php

namespace App\Http\Controllers\Nube;

use App\Http\Controllers\Controller;
use App\Models\NubeCuenta;
use App\Services\Nube\EstadoSincronizacion;
use Illuminate\Contracts\View\View;

/**
 * "Estado de sincronización" (Administrador y Jefe de Zona): orígenes, cuentas
 * y fallos recientes de un vistazo. Solo lectura; "Sincronizar ahora" usa la
 * ruta de orígenes de cada sección.
 */
class NubeEstadoController extends Controller
{
    public function __construct(private readonly EstadoSincronizacion $estado) {}

    public function index(): View
    {
        $this->authorize('verEstado', NubeCuenta::class);

        return view('nube.estado', $this->estado->panel());
    }
}
