<?php

namespace App\Http\Controllers\Interno;

use App\Http\Controllers\Controller;
use App\Http\Requests\Interno\PortalFotoRequest;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Foto de perfil única (puente con el portal). El portal la lee (herencia inicial) y la reparte cuando cambia en
 * cualquier sistema. Lo recibido por aquí NO se vuelve a avisar al portal (sin eco).
 */
class PortalFotoController extends Controller
{
    public function show(PortalFotoRequest $request, AvatarService $avatares): JsonResponse
    {
        $user = $this->cuenta((string) $request->validated('email'));

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $bytes = $avatares->bytes($user);

        return response()->json(['imagen' => $bytes === null ? null : base64_encode($bytes)]);
    }

    public function update(PortalFotoRequest $request, AvatarService $avatares): JsonResponse
    {
        $datos = $request->validated();
        $user = $this->cuenta($datos['email']);

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $bytes = base64_decode($datos['imagen'], true);

        if ($bytes === false) {
            return response()->json(['error' => 'invalid'], 422);
        }

        try {
            $avatares->guardarBytes($user, $bytes, propagar: false);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'invalid_image'], 422);
        }

        return response()->json(['estado' => 'ok']);
    }

    public function destroy(PortalFotoRequest $request, AvatarService $avatares): JsonResponse
    {
        $user = $this->cuenta((string) $request->validated('email'));

        if ($user === null) {
            return response()->json(['estado' => 'sin_cuenta'], 404);
        }

        $avatares->quitar($user, propagar: false);

        return response()->json(['estado' => 'ok']);
    }

    private function cuenta(string $correo): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [mb_strtolower($correo)])->first();
    }
}
