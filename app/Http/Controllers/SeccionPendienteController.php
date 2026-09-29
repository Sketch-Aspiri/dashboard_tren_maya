<?php

namespace App\Http\Controllers;

use App\Support\MenuNavegacion;
use Illuminate\Contracts\View\View;

/**
 * Página genérica "pendiente de agregar información" para los conceptos de
 * "Dashboard Tren Maya.pptx" que aún no tienen módulo (config/navegacion.php).
 */
class SeccionPendienteController extends Controller
{
    public function __construct(private readonly MenuNavegacion $menu) {}

    public function show(string $grupo, string $seccion): View
    {
        $this->authorize('view-seccion-pendiente');

        $pendiente = $this->menu->buscarPendiente($grupo, $seccion);
        abort_if($pendiente === null, 404);

        return view('secciones.pendiente', $pendiente);
    }
}
