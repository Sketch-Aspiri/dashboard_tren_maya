<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarCatalogoPapeleriaRequest;
use App\Models\CatalogoPapeleria;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Mantenimiento del catálogo de papelería (solo jefatura): alta, edición y
 * baja de artículos. El acceso vive en CatalogoPapeleriaPolicy.
 */
class CatalogoPapeleriaController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', CatalogoPapeleria::class);

        $termino = trim((string) $request->query('q', ''));

        $articulos = CatalogoPapeleria::query()
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', '%'.addcslashes(mb_substr($termino, 0, 100), '%_\\').'%'))
            ->orderBy('descripcion')
            ->paginate(25)
            ->withQueryString();

        return view('papeleria.catalogo', ['articulos' => $articulos, 'termino' => $termino]);
    }

    public function store(GuardarCatalogoPapeleriaRequest $request): RedirectResponse
    {
        CatalogoPapeleria::query()->create(['descripcion' => trim($request->validated('descripcion')), 'activo' => true]);

        return redirect()->route('papeleria.catalogo.index')->with('status', __('Artículo agregado al catálogo.'));
    }

    public function update(CatalogoPapeleria $catalogoPapeleria, GuardarCatalogoPapeleriaRequest $request): RedirectResponse
    {
        $catalogoPapeleria->update([
            'descripcion' => trim($request->validated('descripcion')),
            'activo' => $request->boolean('activo'),
        ]);

        return redirect()->back()->with('status', __('Artículo actualizado.'));
    }

    public function destroy(CatalogoPapeleria $catalogoPapeleria): RedirectResponse
    {
        $this->authorize('manage', CatalogoPapeleria::class);

        $catalogoPapeleria->delete();

        return redirect()->back()->with('status', __('Artículo eliminado del catálogo.'));
    }
}
