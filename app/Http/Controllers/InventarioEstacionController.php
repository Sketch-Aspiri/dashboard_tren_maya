<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarInventarioRequest;
use App\Models\Estacion;
use App\Models\InventarioEstacion;
use App\Services\InventarioEstacionService;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * Inventario de cada estación (de sus resguardos) y la verificación en vivo de
 * un pedido contra ese inventario. Solo lectura; el inventario se carga con
 * `php artisan insumos:cargar-datos`.
 */
class InventarioEstacionController extends Controller
{
    public function __construct(private readonly InventarioEstacionService $service) {}

    public function index(ConsultarInventarioRequest $request): View
    {
        $usuario = $request->user();
        $esEstacion = $usuario->hasRole(Roles::ESTACION);
        $estaciones = $esEstacion
            ? Estacion::query()->whereKey($usuario->estacion_id)->get()
            : Estacion::query()->orderBy('orden')->get();
        $estacion = $estaciones->firstWhere('id', (int) $request->validated('estacion')) ?? $estaciones->first();
        $tipo = $request->validated('tipo');
        $termino = trim((string) $request->validated('q'));

        $filas = $estacion === null ? collect() : InventarioEstacion::query()
            ->where('estacion_id', $estacion->id)
            ->when($tipo !== null, fn ($q) => $q->where('tipo', $tipo))
            ->when($termino !== '', fn ($q) => $q->where('descripcion', 'like', '%'.addcslashes($termino, '%_\\').'%'))
            ->orderBy('tipo')->orderBy('descripcion')
            ->get();

        return view('insumos.inventario', [
            'estaciones' => $estaciones,
            'estacion' => $estacion,
            'filas' => $filas,
            'tipo' => $tipo,
            'q' => $termino,
            'conteo' => $estacion === null ? [] : InventarioEstacion::query()->where('estacion_id', $estacion->id)->selectRaw('tipo, count(*) as n')->groupBy('tipo')->pluck('n', 'tipo')->all(),
            'mesesConsumibles' => (int) config('insumos.inventario.meses_consumibles'),
        ]);
    }

    /**
     * Avisos de un pedido (JSON interno, mismo auth que la web).
     */
    public function verificar(ConsultarInventarioRequest $request): JsonResponse
    {
        $avisos = $request->validated('estacion') === null || trim((string) $request->validated('descripcion')) === ''
            ? []
            : $this->service->avisos((int) $request->validated('estacion'), (string) $request->validated('descripcion'));

        return response()->json(['success' => true, 'data' => $avisos, 'error' => null, 'meta' => null]);
    }
}
