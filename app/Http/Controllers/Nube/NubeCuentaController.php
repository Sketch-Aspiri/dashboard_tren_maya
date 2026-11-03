<?php

namespace App\Http\Controllers\Nube;

use App\Enums\ProveedorNube;
use App\Http\Controllers\Controller;
use App\Models\NubeCuenta;
use App\Services\Nube\CredencialesNubeService;
use App\Services\Nube\CuentaNubeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Conexiones de nube" (solo Administrador): credenciales por proveedor y
 * cuentas conectadas. Delgado: la lógica vive en los servicios de Nube.
 */
class NubeCuentaController extends Controller
{
    public function __construct(
        private readonly CredencialesNubeService $credenciales,
        private readonly CuentaNubeService $cuentas,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', NubeCuenta::class);

        $cuentas = NubeCuenta::query()->orderBy('cuenta_email')->get()->groupBy(fn (NubeCuenta $c): string => $c->proveedor->value);

        $tarjetas = collect(ProveedorNube::cases())->map(fn (ProveedorNube $proveedor): array => [
            'proveedor' => $proveedor,
            'estado' => $this->credenciales->estado($proveedor),
            'redirectUri' => $this->credenciales->redirectUri($proveedor),
            'cuentas' => $cuentas->get($proveedor->value, collect()),
        ]);

        return view('nube.cuentas', ['tarjetas' => $tarjetas]);
    }

    public function destroy(Request $request, NubeCuenta $cuenta): RedirectResponse
    {
        $this->authorize('delete', $cuenta);

        $this->cuentas->desconectar($cuenta, $request->user());

        return redirect()->route('nube.cuentas.index')
            ->with('status', __('Cuenta desconectada. Los orígenes que la usaban quedaron en pausa.'));
    }
}
