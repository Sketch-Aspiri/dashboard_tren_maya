<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarMenuGrupoRequest;
use App\Http\Requests\Secciones\MoverElementoRequest;
use App\Models\MenuGrupo;
use App\Services\Secciones\MenuService;
use Illuminate\Http\RedirectResponse;

class MenuGrupoController extends Controller
{
    public function __construct(private readonly MenuService $menu) {}

    public function store(GuardarMenuGrupoRequest $request): RedirectResponse
    {
        $this->authorize('create', MenuGrupo::class);

        $grupo = $this->menu->crearGrupo($request->validated('nombre'));

        return $this->volver(__('Grupo ":nombre" creado.', ['nombre' => $grupo->nombre]));
    }

    public function update(GuardarMenuGrupoRequest $request, MenuGrupo $grupo): RedirectResponse
    {
        $this->authorize('update', $grupo);

        $this->menu->renombrarGrupo($grupo, $request->validated('nombre'));

        return $this->volver(__('Grupo actualizado.'));
    }

    public function mover(MoverElementoRequest $request, MenuGrupo $grupo): RedirectResponse
    {
        $this->authorize('update', $grupo);

        $this->menu->moverGrupo($grupo, $request->validated('direccion'));

        return redirect()->to(route('secciones.administrar').'#grupo-'.$grupo->id);
    }

    public function destroy(MenuGrupo $grupo): RedirectResponse
    {
        $this->authorize('delete', $grupo);

        $this->menu->eliminarGrupo($grupo);

        return $this->volver(__('Grupo eliminado.'));
    }

    private function volver(?string $mensaje = null): RedirectResponse
    {
        return redirect()->route('secciones.administrar')->with('status', $mensaje);
    }
}
