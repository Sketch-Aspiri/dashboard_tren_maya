<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardarProveedorRequest;
use App\Models\CatalogoInsumo;
use App\Models\Proveedor;
use App\Models\SolicitudInsumoPartida;
use App\Services\ProveedorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Proveedores del fondo rotatorio y su IVA (8 % o 16 %). La jefatura asigna
 * la tasa de cada proveedor; con ella se calcula el IVA por separado en
 * las solicitudes y en el ANEXO. Controlador delgado; el acceso vive en
 * ProveedorPolicy.
 */
class ProveedorController extends Controller
{
    public function __construct(private readonly ProveedorService $service) {}

    public function index(): View
    {
        $this->authorize('viewAny', Proveedor::class);

        // Recoge los proveedores que ya aparecen en el catálogo y en las partidas.
        $this->service->sincronizar();

        $proveedores = Proveedor::query()->orderBy('nombre')->get();

        return view('insumos.proveedores', [
            'proveedores' => $proveedores,
            'tasas' => config('insumos.tasas_iva'),
            'sinDefinir' => $proveedores->whereNull('iva')->count(),
            'defecto' => (int) config('insumos.iva_defecto'),
        ]);
    }

    public function store(GuardarProveedorRequest $request): RedirectResponse
    {
        Proveedor::query()->create([
            'nombre' => trim((string) preg_replace('/\s+/u', ' ', $request->validated('nombre'))),
            'iva' => $request->validated('iva'),
        ]);

        return redirect()->route('insumos.proveedores.index')->with('status', __('Proveedor agregado.'));
    }

    public function update(Proveedor $proveedor, GuardarProveedorRequest $request): RedirectResponse
    {
        $proveedor->update(['iva' => $request->validated('iva')]);

        return redirect()->back()->with('status', __('IVA de :nombre actualizado.', ['nombre' => $proveedor->nombre]));
    }

    public function destroy(Proveedor $proveedor): RedirectResponse
    {
        $this->authorize('manage', Proveedor::class);

        $enUso = CatalogoInsumo::query()->where('proveedor', $proveedor->nombre)->exists()
            || SolicitudInsumoPartida::query()->where('proveedor', $proveedor->nombre)->exists();

        if ($enUso) {
            return redirect()->back()->withErrors(['proveedor' => __('No se puede eliminar: el proveedor está en el catálogo o en alguna solicitud.')]);
        }

        $proveedor->delete();

        return redirect()->back()->with('status', __('Proveedor eliminado.'));
    }
}
