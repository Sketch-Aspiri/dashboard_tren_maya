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
 * con vista previa (cuántos se crean/actualizan) antes de aplicar.
 */
class PersonalImportacionController extends Controller
{
    private const SESION = 'personal_importacion';

    public function __construct(private readonly PersonalImportacionService $importacion) {}

    public function store(ImportarPersonalRequest $request): JsonResponse|RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $anterior = $request->session()->get(self::SESION);
        if ($this->importacion->existe($anterior)) {
            $this->importacion->descartar($anterior);
        }

        $request->session()->put(self::SESION, $this->importacion->guardarPendiente($request->archivo()));

        $destino = route('agenda.personal.importar.show');

        return $request->expectsJson()
            ? response()->json(['success' => true, 'data' => ['archivos' => [], 'redirigir' => $destino], 'error' => null, 'meta' => null], 201)
            : redirect()->to($destino);
    }

    public function show(Request $request): View|RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $ruta = $request->session()->get(self::SESION);

        if (! $this->importacion->existe($ruta)) {
            return redirect()->route('agenda.personal.index');
        }

        return view('agenda.personal.importar', ['resumen' => $this->importacion->vistaPrevia($ruta)]);
    }

    public function aplicar(Request $request): RedirectResponse
    {
        $this->authorize('importar', Empleado::class);

        $ruta = $request->session()->pull(self::SESION);

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

        $ruta = $request->session()->pull(self::SESION);

        if ($this->importacion->existe($ruta)) {
            $this->importacion->descartar($ruta);
        }

        return redirect()->route('agenda.personal.index')->with('status', __('Importación cancelada; no se cambió nada.'));
    }
}
