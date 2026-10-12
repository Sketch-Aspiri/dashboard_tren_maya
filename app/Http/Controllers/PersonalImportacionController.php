<?php

namespace App\Http\Controllers;

use App\Http\Requests\ImportarPersonalRequest;
use App\Models\Empleado;
use App\Services\PersonalImportacionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * RR.HH. > Personal: importar el Excel de la base de datos arrastrándolo,
 * con vista previa (cuántos se crean/actualizan) antes de aplicar. La vista
 * previa se calcula UNA vez al subir y se guarda en sesión: recargar la
 * página no vuelve a correr la importación de prueba.
 */
class PersonalImportacionController extends Controller
{
    private const SESION = 'personal_importacion';

    public function __construct(private readonly PersonalImportacionService $importacion) {}

    public function store(ImportarPersonalRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $this->descartarPendiente($request);

        $ruta = $this->importacion->guardarPendiente($request->archivo());

        try {
            $resumen = $this->importacion->vistaPrevia($ruta);
        } catch (\Throwable $e) {
            $this->importacion->descartar($ruta);

            throw $e;
        }

        $request->session()->put(self::SESION, ['ruta' => $ruta, 'resumen' => $resumen]);
        $destino = route('agenda.personal.importar.show');

        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['archivos' => [], 'redirigir' => $destino], 'error' => null, 'meta' => null], 201)
            : redirect()->to($destino);
    }

    public function show(Request $request): View|RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $pendiente = $request->session()->get(self::SESION);

        if (! is_array($pendiente) || ! $this->importacion->existe($pendiente['ruta'] ?? null)) {
            return redirect()->route('agenda.personal.index');
        }

        return view('agenda.personal.importar', ['resumen' => $pendiente['resumen']]);
    }

    public function aplicar(Request $request): RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $pendiente = $request->session()->pull(self::SESION);
        $ruta = is_array($pendiente) ? ($pendiente['ruta'] ?? null) : null;

        if (! $this->importacion->existe($ruta)) {
            return redirect()->route('agenda.personal.index');
        }

        $resumen = $this->importacion->aplicar($ruta, $request->user());

        return redirect()->route('agenda.personal.index')->with('status', __(
            'Personal importado: :c nuevos y :a actualizados (:t en total).',
            ['c' => $resumen['creados'], 'a' => $resumen['actualizados'], 't' => $resumen['total_despues']],
        ));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $this->descartarPendiente($request);

        return redirect()->route('agenda.personal.index')->with('status', __('Importación descartada; no se cambió nada.'));
    }

    private function descartarPendiente(Request $request): void
    {
        $pendiente = $request->session()->pull(self::SESION);
        $ruta = is_array($pendiente) ? ($pendiente['ruta'] ?? null) : null;

        if ($this->importacion->existe($ruta)) {
            $this->importacion->descartar($ruta);
        }
    }
}
