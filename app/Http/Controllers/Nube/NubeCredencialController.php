<?php

namespace App\Http\Controllers\Nube;

use App\Enums\ProveedorNube;
use App\Http\Controllers\Controller;
use App\Http\Requests\Nube\GuardarCredencialesNubeRequest;
use App\Services\Nube\CredencialesNubeService;
use Illuminate\Http\RedirectResponse;

class NubeCredencialController extends Controller
{
    public function __construct(private readonly CredencialesNubeService $credenciales) {}

    public function update(GuardarCredencialesNubeRequest $request, ProveedorNube $proveedor): RedirectResponse
    {
        $this->credenciales->guardar($proveedor, $request->validated(), $request->user());

        return redirect()->route('nube.cuentas.index')
            ->with('status', __('Credenciales de :nube guardadas.', ['nube' => $proveedor->label()]));
    }
}
