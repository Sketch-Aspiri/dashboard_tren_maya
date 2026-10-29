<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\VincularNubeRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Services\Secciones\NubeSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Vínculo de un documento con un enlace compartido de la nube. La descarga
 * periódica la hace el comando secciones:sincronizar-nube.
 */
class SeccionArchivoNubeController extends Controller
{
    public function __construct(private readonly NubeSyncService $nube) {}

    public function vincular(VincularNubeRequest $request, Seccion $seccion, SeccionArchivo $archivo): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->nube->vincular($archivo, $request->validated('enlace'), $request->user());

        return back()->with('status', __('Documento vinculado. Se actualizará solo cada :min minutos; también puedes usar "Sincronizar ahora".', ['min' => config('secciones.nube.minutos')]));
    }

    public function sincronizar(Request $request, Seccion $seccion, SeccionArchivo $archivo): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        abort_unless($archivo->estaVinculadoANube(), 404);

        $resultado = $this->nube->sincronizar($archivo, $request->user());
        $archivo->refresh();

        return match ($resultado) {
            NubeSyncService::ACTUALIZADO => back()->with('status', $archivo->sincronizacion_error ?? __('Documento actualizado desde la nube.')),
            NubeSyncService::SIN_CAMBIOS => back()->with('status', __('El documento ya estaba al día.')),
            NubeSyncService::OCUPADO => back()->with('status', __('Ya se está sincronizando este documento. Intenta de nuevo en un momento.')),
            default => back()->withErrors(['nube' => $archivo->sincronizacion_error]),
        };
    }

    public function desvincular(Request $request, Seccion $seccion, SeccionArchivo $archivo): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->nube->desvincular($archivo, $request->user());

        return back()->with('status', __('Documento desvinculado de la nube. El archivo guardado se conserva.'));
    }
}
