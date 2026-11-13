<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\PortalCuentaRequest;
use App\Models\User;
use App\Services\Ecosistema\PortalAprovisionamiento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cuentas para el portal (puente interno): alta de la cuenta aprobada y, para la simulación de emparejado, un
 * listado sin datos sensibles (nunca contraseñas, 2FA ni tokens).
 */
class PortalCuentasController extends Controller
{
    public function store(PortalCuentaRequest $request, PortalAprovisionamiento $aprovisionamiento): JsonResponse
    {
        $resultado = $aprovisionamiento->aprovisionar($request->validated());

        // 409: ya hay una cuenta con ese correo que no es de este alta; el portal lo muestra como conflicto.
        return response()->json($resultado, $resultado['estado'] === PortalAprovisionamiento::CORREO_EXISTENTE ? 409 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $despues = max(0, (int) $request->query('despues', 0));
        $limite = min(500, max(1, (int) $request->query('limite', 500)));

        $usuarios = User::query()->with('roles:id,name')
            ->where('id', '>', $despues)
            ->where('email', 'not like', '%.invalid')
            ->orderBy('id')->limit($limite + 1)
            ->get(['id', 'email', 'name', 'sso_vinculado_at', 'portal_user_id']);

        $pagina = $usuarios->take($limite);

        return response()->json([
            'cuentas' => $pagina->map(fn (User $user): array => [
                'email' => mb_strtolower((string) $user->email),
                'nombre' => $user->name,
                'estado' => 'active',
                'rol' => $user->roles->pluck('name')->implode(', ') ?: null,
                'vinculada' => $user->sso_vinculado_at !== null,
                'emparejada' => $user->portal_user_id !== null,
            ])->values()->all(),
            'siguiente' => $usuarios->count() > $limite ? $pagina->last()->id : null,
        ]);
    }
}
