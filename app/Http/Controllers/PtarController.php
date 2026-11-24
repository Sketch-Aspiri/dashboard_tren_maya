<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarPtarRequest;
use App\Http\Requests\GuardarEvidenciaPtarRequest;
use App\Http\Requests\GuardarPtarRequest;
use App\Http\Requests\RevisarPtarRequest;
use App\Models\Estacion;
use App\Models\PtarEvidencia;
use App\Models\PtarPlanta;
use App\Models\PtarRegistro;
use App\Services\Ptar\PtarService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PTAR: la Zona ve el tablero del mes; la estación va directo a su registro, captura equipos, actividades y
 * fotos y lo envía; la Zona revisa y aprueba o devuelve.
 */
class PtarController extends Controller
{
    public function __construct(private readonly PtarService $service) {}

    public function index(ConsultarPtarRequest $request): View|RedirectResponse
    {
        [$anio, $mes] = $this->periodo($request);
        $usuario = $request->user();

        if ($usuario->hasRole('Estación')) {
            return redirect()->route('ptar.estacion', ['estacion' => $usuario->estacion_id, 'anio' => $anio, 'mes' => $mes]);
        }

        $tablero = $this->service->tablero($anio, $mes);

        return view('ptar.index', [
            'anio' => $anio,
            'mes' => $mes,
            'tablero' => $tablero,
            'capturables' => $tablero->pluck('estacion.id')->filter(fn (int $id) => $usuario->can('update', new PtarRegistro(['estacion_id' => $id, 'estado' => PtarRegistro::BORRADOR])))->values()->all(),
            'puedeConsolidar' => $usuario->can('gestionar', PtarRegistro::class),
        ]);
    }

    public function estacion(Estacion $estacion, ConsultarPtarRequest $request): RedirectResponse
    {
        $this->authorize('manageFor', [PtarRegistro::class, $estacion]);

        PtarPlanta::sincronizar();
        abort_unless(PtarPlanta::query()->where('estacion_id', $estacion->id)->where('activa', true)->exists(), 404);

        [$anio, $mes] = $this->periodo($request);
        $usuario = $request->user();

        // Solo quien captura esa planta abre (y crea) el registro; la Zona consulta los que ya existen.
        if (! $usuario->can('update', new PtarRegistro(['estacion_id' => $estacion->id, 'estado' => PtarRegistro::BORRADOR]))) {
            $existente = $this->service->buscar($estacion, $anio, $mes);

            return $existente === null
                ? redirect()->route('ptar.index', ['anio' => $anio, 'mes' => $mes])->with('status', __('Esa estación aún no captura su registro de este mes.'))
                : redirect()->route('ptar.show', $existente);
        }

        return redirect()->route('ptar.show', $this->service->obtenerOCrear($estacion, $anio, $mes, $usuario));
    }

    public function show(PtarRegistro $registro, Request $request): View
    {
        $this->authorize('view', $registro);

        $registro->load(['estacion', 'equipos', 'actividades', 'evidencias', 'revisor']);

        return view('ptar.show', [
            'registro' => $registro,
            'semaforo' => $this->service->semaforo($registro),
            'puedeEditar' => $request->user()->can('update', $registro),
            'puedeRevisar' => $request->user()->can('review', $registro),
            'maxEvidencias' => (int) config('ptar.max_evidencias'),
        ]);
    }

    public function guardar(PtarRegistro $registro, GuardarPtarRequest $request): RedirectResponse
    {
        $this->service->guardar($registro, $request->validated(), $request->user());

        return redirect()->route('ptar.show', $registro)->with('status', __('Captura guardada.'));
    }

    public function agregarEvidencia(PtarRegistro $registro, GuardarEvidenciaPtarRequest $request): RedirectResponse
    {
        $this->service->agregarEvidencia($registro, $request->file('foto'), $request->validated('pie'), $request->user());

        return redirect()->route('ptar.show', $registro)->with('status', __('Foto agregada.'));
    }

    public function quitarEvidencia(PtarRegistro $registro, PtarEvidencia $evidencia, Request $request): RedirectResponse
    {
        abort_unless($evidencia->registro_id === $registro->id, 404);
        $this->authorize('update', $registro);

        $this->service->quitarEvidencia($evidencia, $request->user());

        return redirect()->route('ptar.show', $registro)->with('status', __('Foto eliminada.'));
    }

    public function evidencia(PtarRegistro $registro, PtarEvidencia $evidencia): StreamedResponse
    {
        abort_unless($evidencia->registro_id === $registro->id, 404);
        $this->authorize('view', $registro);
        abort_unless(Storage::disk('local')->exists($evidencia->ruta), 404);

        return Storage::disk('local')->response($evidencia->ruta, $evidencia->nombre, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function enviar(PtarRegistro $registro, Request $request): RedirectResponse
    {
        $this->authorize('update', $registro);

        $this->service->enviar($registro, $request->user());

        return redirect()->route('ptar.show', $registro)->with('status', __('Registro enviado a la Zona para revisión.'));
    }

    public function revisar(PtarRegistro $registro, RevisarPtarRequest $request): RedirectResponse
    {
        $this->service->revisar($registro, $request->validated(), $request->user());

        return redirect()->route('ptar.show', $registro)->with('status', __('Revisión guardada.'));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function periodo(ConsultarPtarRequest $request): array
    {
        $actual = $this->service->mesActual();

        return [(int) ($request->validated('anio') ?? $actual['anio']), (int) ($request->validated('mes') ?? $actual['mes'])];
    }
}
