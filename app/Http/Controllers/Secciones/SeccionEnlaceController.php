<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\GuardarSeccionEnlaceRequest;
use App\Models\Seccion;
use App\Models\SeccionEnlace;
use Illuminate\Http\RedirectResponse;

/**
 * Enlaces de una sección (pestaña Enlaces).
 */
class SeccionEnlaceController extends Controller
{
    public function store(GuardarSeccionEnlaceRequest $request, Seccion $seccion): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $enlace = new SeccionEnlace($request->validated());
        $enlace->forceFill(['seccion_id' => $seccion->id, 'creado_por' => $request->user()->id])->save();

        return $this->volver($seccion, __('Enlace «:titulo» agregado.', ['titulo' => $enlace->titulo]));
    }

    public function update(GuardarSeccionEnlaceRequest $request, Seccion $seccion, SeccionEnlace $enlace): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $enlace->update($request->validated());

        return $this->volver($seccion, __('Enlace actualizado.'));
    }

    public function destroy(Seccion $seccion, SeccionEnlace $enlace): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $enlace->delete();

        return $this->volver($seccion, __('Enlace «:titulo» eliminado.', ['titulo' => $enlace->titulo]));
    }

    private function volver(Seccion $seccion, string $mensaje): RedirectResponse
    {
        return redirect()->route('secciones.show', [$seccion, 'tab' => 'enlaces'])->with('status', $mensaje);
    }
}
