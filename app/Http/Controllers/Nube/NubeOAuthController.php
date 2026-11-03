<?php

namespace App\Http\Controllers\Nube;

use App\Enums\ProveedorNube;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nube\CallbackNubeRequest;
use App\Models\NubeCuenta;
use App\Services\Nube\CuentaNubeService;
use App\Services\Nube\NubeOAuthException;
use App\Services\Nube\OAuthNubeService;
use Illuminate\Http\RedirectResponse;

class NubeOAuthController extends Controller
{
    public function __construct(
        private readonly OAuthNubeService $oauth,
        private readonly CuentaNubeService $cuentas,
    ) {}

    public function conectar(ProveedorNube $proveedor): RedirectResponse
    {
        $this->authorize('create', NubeCuenta::class);

        try {
            return redirect()->away($this->oauth->urlAutorizacion($proveedor));
        } catch (NubeOAuthException $e) {
            return redirect()->route('nube.cuentas.index')->with('error', $e->getMessage());
        }
    }

    public function callback(CallbackNubeRequest $request, ProveedorNube $proveedor): RedirectResponse
    {
        try {
            $cuenta = $this->cuentas->conectarDesdeCallback($proveedor, $request->validated(), $request->user());
        } catch (NubeOAuthException $e) {
            return redirect()->route('nube.cuentas.index')->with('error', $e->getMessage());
        }

        return redirect()->route('nube.cuentas.index')
            ->with('status', __('Cuenta :correo conectada.', ['correo' => $cuenta->cuenta_email]));
    }
}
