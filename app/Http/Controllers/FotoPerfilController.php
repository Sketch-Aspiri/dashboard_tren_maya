<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarFotoPerfilRequest;
use App\Models\User;
use App\Services\AvatarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de perfil: el archivo vive en el disco privado y solo se sirve por aquí a quien tiene sesión. Siempre es un
 * JPEG generado por el servidor (AvatarService).
 */
class FotoPerfilController extends Controller
{
    public function __construct(private readonly AvatarService $avatares) {}

    public function show(User $user): StreamedResponse
    {
        $disco = Storage::disk('local');

        abort_unless($user->avatar_path !== null && $disco->exists($user->avatar_path), 404);

        return $disco->response($user->avatar_path, 'avatar.jpg', [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            // La URL lleva `v` (cambia con cada foto): el navegador puede guardarla sin verla vieja.
            'Cache-Control' => 'private, max-age=86400',
        ], 'inline');
    }

    public function update(ActualizarFotoPerfilRequest $request): RedirectResponse
    {
        try {
            $this->avatares->actualizar($request->user(), $request->file('foto'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['foto' => $e->getMessage()]);
        }

        return redirect()->route('profile.edit')->with('status', 'foto-updated');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->avatares->quitar($request->user());

        return redirect()->route('profile.edit')->with('status', 'foto-removed');
    }
}
