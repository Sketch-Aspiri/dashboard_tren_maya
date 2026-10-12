<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Models\MenuGrupo;
use App\Models\Seccion;
use Illuminate\Contracts\View\View;

/**
 * "Administrar menú": grupos y secciones, con alta, cambio de nombre,
 * orden, visibilidad y baja.
 */
class AdministrarMenuController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', MenuGrupo::class);
        $this->authorize('create', Seccion::class);

        $grupos = MenuGrupo::query()
            ->orderBy('orden')
            ->orderBy('id')
            ->with(['secciones' => fn ($consulta) => $consulta->withCount(['filas', 'archivos'])])
            ->get();

        return view('secciones.administrar', ['grupos' => $grupos]);
    }
}
