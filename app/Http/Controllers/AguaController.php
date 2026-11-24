<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarProgramaAguaRequest;
use App\Http\Requests\ConsultarAguaRequest;
use App\Http\Requests\GuardarCompraAguaRequest;
use App\Http\Requests\RevisarAguaRequest;
use App\Models\AguaCompra;
use App\Models\AguaRegistro;
use App\Models\Estacion;
use App\Services\Agua\AguaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Registro de agua: la Zona ve el tablero del mes; la estación va directo a su registro, captura sus compras
 * con comprobante y las envía; la Zona fija el programa, revisa y aprueba.
 */
class AguaController extends Controller
{
    public function __construct(private readonly AguaService $service) {}

    public function index(ConsultarAguaRequest $request): View|RedirectResponse
    {
        [$anio, $mes] = $this->periodo($request);
        $usuario = $request->user();

        if ($usuario->hasRole('Estación')) {
            return redirect()->route('agua.estacion', ['estacion' => $usuario->estacion_id, 'anio' => $anio, 'mes' => $mes]);
        }

        return view('agua.index', [
            'anio' => $anio,
            'mes' => $mes,
            'tablero' => $this->service->tablero($anio, $mes),
            'puedeConsolidar' => $usuario->can('gestionar', AguaRegistro::class),
        ]);
    }

    public function estacion(Estacion $estacion, ConsultarAguaRequest $request): RedirectResponse
    {
        $this->authorize('manageFor', [AguaRegistro::class, $estacion]);

        [$anio, $mes] = $this->periodo($request);
        $registro = $this->service->obtenerOCrear($estacion, $anio, $mes, $request->user());

        return redirect()->route('agua.show', $registro);
    }

    public function show(AguaRegistro $registro, Request $request): View
    {
        $this->authorize('view', $registro);

        $registro->load(['estacion', 'compras', 'revisor']);

        return view('agua.show', [
            'registro' => $registro,
            'puedeEditar' => $request->user()->can('update', $registro),
            'puedeRevisar' => $request->user()->can('review', $registro),
            'puedeProgramar' => $request->user()->can('gestionar', AguaRegistro::class),
            'frecuencias' => config('agua.frecuencias'),
            'maxCompras' => (int) config('agua.max_compras'),
        ]);
    }

    public function programa(AguaRegistro $registro, ActualizarProgramaAguaRequest $request): RedirectResponse
    {
        $this->service->actualizarPrograma($registro, $request->validated(), $request->user());

        return redirect()->route('agua.show', $registro)->with('status', __('Programa del mes guardado.'));
    }

    public function agregarCompra(AguaRegistro $registro, GuardarCompraAguaRequest $request): RedirectResponse
    {
        $this->service->agregarCompra($registro, $request->safe()->only(['fecha', 'garrafones', 'nota']), $request->file('comprobante'), $request->user());

        return redirect()->route('agua.show', $registro)->with('status', __('Compra registrada.'));
    }

    public function quitarCompra(AguaRegistro $registro, AguaCompra $compra, Request $request): RedirectResponse
    {
        abort_unless($compra->registro_id === $registro->id, 404);
        $this->authorize('update', $registro);

        $this->service->quitarCompra($compra, $request->user());

        return redirect()->route('agua.show', $registro)->with('status', __('Compra eliminada.'));
    }

    public function comprobante(AguaRegistro $registro, AguaCompra $compra): StreamedResponse
    {
        abort_unless($compra->registro_id === $registro->id, 404);
        $this->authorize('view', $registro);
        abort_if($compra->comprobante_ruta === null || ! Storage::disk('local')->exists($compra->comprobante_ruta), 404);

        return Storage::disk('local')->response($compra->comprobante_ruta, $compra->comprobante_nombre ?? 'comprobante', [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function enviar(AguaRegistro $registro, Request $request): RedirectResponse
    {
        $this->authorize('update', $registro);

        $this->service->enviar($registro, $request->user());

        return redirect()->route('agua.show', $registro)->with('status', __('Registro enviado a la Zona para revisión.'));
    }

    public function revisar(AguaRegistro $registro, RevisarAguaRequest $request): RedirectResponse
    {
        $this->service->revisar($registro, $request->validated(), $request->user());

        return redirect()->route('agua.show', $registro)->with('status', __('Revisión guardada.'));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function periodo(ConsultarAguaRequest $request): array
    {
        $actual = $this->service->mesActual();

        return [(int) ($request->validated('anio') ?? $actual['anio']), (int) ($request->validated('mes') ?? $actual['mes'])];
    }
}
