<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\PortalCuentaCambioRequest;
use App\Http\Requests\Interno\PortalCuentaRequest;
use App\Models\User;
use App\Services\Ecosistema\PortalAprovisionamiento;
use App\Services\Ecosistema\PortalCuentaSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /** Estado y/o rol de una cuenta ya emparejada. 404 si ese portal_user_id no existe aqui; 409 si dejaria al sistema sin Administrador. */
    public function update(PortalCuentaCambioRequest $request, string $portalUserId, PortalCuentaSync $sync): JsonResponse
    {
        abort_unless(Str::isUuid($portalUserId), 404);

        $resultado = $sync->sincronizar($portalUserId, $request->validated());

        return response()->json($resultado, match ($resultado['estado']) {
            PortalCuentaSync::NO_ENCONTRADA => 404,
            PortalCuentaSync::ULTIMO_ADMIN => 409,
            default => 200,
        });
    }

    public function index(Request $request): JsonResponse
    {
        $despues = max(0, (int) $request->query('despues', 0));
        $limite = min(500, max(1, (int) $request->query('limite', 500)));

        $usuarios = User::query()->with('roles:id,name')
            ->where('id', '>', $despues)
            ->where('email', 'not like', '%.invalid')
            ->orderBy('id')->limit($limite + 1)
            ->get(['id', 'email', 'name', 'sso_vinculado_at', 'portal_user_id', 'desactivado_at']);

        $pagina = $usuarios->take($limite);

        return response()->json([
            'cuentas' => $pagina->map(fn (User $user): array => [
                'email' => mb_strtolower((string) $user->email),
                'nombre' => $user->name,
                'estado' => $user->desactivado_at === null ? 'active' : 'inactive',
                'rol' => $user->roles->pluck('name')->implode(', ') ?: null,
                'vinculada' => $user->sso_vinculado_at !== null,
                'emparejada' => $user->portal_user_id !== null,
            ])->values()->all(),
            'siguiente' => $usuarios->count() > $limite ? $pagina->last()->id : null,
        ]);
    }
}
