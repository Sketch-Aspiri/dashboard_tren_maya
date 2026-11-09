<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarCatalogoInsumosRequest;
use App\Http\Requests\GuardarCatalogoInsumoRequest;
use App\Http\Requests\ImportarCatalogoInsumosRequest;
use App\Models\CatalogoInsumo;
use App\Services\CatalogoInsumoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * Pantalla de mantenimiento del catálogo de insumos (solo jefatura):
 * alta, edición, baja y carga desde un ANEXO en Excel. Controlador delgado;
 * el acceso vive en CatalogoInsumoPolicy y la lectura del Excel en
 * CatalogoInsumoService.
 */
class CatalogoInsumoController extends Controller
{
    public function __construct(private readonly CatalogoInsumoService $service) {}

    public function index(ConsultarCatalogoInsumosRequest $request): View
    {
        $termino = trim((string) $request->validated('q', ''));
        $verInactivos = (bool) $request->validated('inactivos', false);

        $insumos = CatalogoInsumo::query()
            ->when(! $verInactivos, fn ($q) => $q->where('activo', true))
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', '%'.addcslashes($termino, '%_\\').'%'))
            ->orderBy('descripcion')
            ->paginate(25)
            ->withQueryString();

        return view('insumos.catalogo', [
            'insumos' => $insumos,
            'termino' => $termino,
            'verInactivos' => $verInactivos,
        ]);
    }

    public function store(GuardarCatalogoInsumoRequest $request): RedirectResponse
    {
        CatalogoInsumo::query()->create($this->datos($request->validated()));

        return redirect()->route('insumos.catalogo.index')->with('status', __('Insumo agregado al catálogo.'));
    }

    public function update(CatalogoInsumo $catalogoInsumo, GuardarCatalogoInsumoRequest $request): RedirectResponse
    {
        $catalogoInsumo->update($this->datos($request->validated()));

        return redirect()->back()->with('status', __('Insumo actualizado.'));
    }

    public function destroy(CatalogoInsumo $catalogoInsumo): RedirectResponse
    {
        $this->authorize('manage', CatalogoInsumo::class);

        $catalogoInsumo->delete();

        return redirect()->back()->with('status', __('Insumo eliminado del catálogo.'));
    }

    public function importar(ImportarCatalogoInsumosRequest $request): RedirectResponse
    {
        try {
            $resumen = $this->service->importarAnexo($request->file('archivo')->getRealPath());
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('insumos.catalogo.index')
                ->withErrors(['archivo' => __('No se pudo leer el archivo. Verifica que sea un ANEXO .xlsx válido.')]);
        }

        activity('catalogo_insumo')->causedBy($request->user())->withProperties($resumen)->log('importado desde ANEXO');

        return redirect()->route('insumos.catalogo.index')->with('status', __(
            'Carga terminada: :leidos partidas leídas, :creados nuevas, :actualizados con precio actualizado y :sin_cambio sin cambios.',
            $resumen,
        ));
    }

    /**
     * @param  array<string, mixed>  $validados
     * @return array<string, mixed>
     */
    private function datos(array $validados): array
    {
        return [...$validados, 'activo' => (bool) ($validados['activo'] ?? false)];
    }
}
