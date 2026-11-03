<?php

namespace App\Http\Controllers\Secciones;

use App\Enums\MotivoOmision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\ActualizarOrigenRequest;
use App\Http\Requests\Secciones\DesvincularOrigenRequest;
use App\Http\Requests\Secciones\VincularOrigenRequest;
use App\Http\Resources\SeccionOrigenResource;
use App\Models\Seccion;
use App\Models\SeccionOrigen;
use App\Services\Nube\Datos\OrigenRemoto;
use App\Services\Secciones\NubeSyncException;
use App\Services\Secciones\OrigenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Orígenes de nube de una sección (carpetas o archivos vinculados). Responde
 * con el sobre JSON del proyecto a las llamadas de Alpine y con redirección
 * a los formularios normales.
 */
class SeccionOrigenController extends Controller
{
    public function __construct(private readonly OrigenService $origenes) {}

    public function inspeccionar(VincularOrigenRequest $request, Seccion $seccion): JsonResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $remoto = $this->inspeccionarOFallar($request);

        return $this->sobre($this->datosDeInspeccion($remoto));
    }

    public function store(VincularOrigenRequest $request, Seccion $seccion): JsonResponse|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        try {
            $origen = $this->origenes->vincular(
                $seccion,
                $request->cuenta(),
                $request->validated('enlace'),
                $request->has('alimenta_tablas') ? $request->boolean('alimenta_tablas') : null,
                $request->boolean('incluir_subcarpetas', true),
                $request->user(),
            );
        } catch (NubeSyncException $e) {
            throw ValidationException::withMessages(['enlace' => $e->getMessage()]);
        }

        $mensaje = __('Vinculado. Los documentos se irán trayendo en unos minutos y se revisan solos cada :min minutos.', ['min' => config('secciones.nube.minutos')]);

        return $request->expectsJson()
            ? $this->sobre(new SeccionOrigenResource($origen->load('cuenta')), 201)
            : back()->with('status', $mensaje);
    }

    public function sincronizar(Request $request, Seccion $seccion, SeccionOrigen $origen): JsonResponse|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->origenes->sincronizarAhora($origen, $request->user());

        return $request->expectsJson()
            ? $this->sobre(['en_cola' => true], 202)
            : back()->with('status', __('Sincronización en marcha. Los cambios aparecen en unos minutos.'));
    }

    public function update(ActualizarOrigenRequest $request, Seccion $seccion, SeccionOrigen $origen): JsonResponse|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $origen = $this->origenes->actualizar($origen->load('cuenta'), $request->validated(), $request->user());

        return $request->expectsJson()
            ? $this->sobre(new SeccionOrigenResource($origen))
            : back()->with('status', __('Origen actualizado.'));
    }

    public function destroy(DesvincularOrigenRequest $request, Seccion $seccion, SeccionOrigen $origen): JsonResponse|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->origenes->desvincular($origen, $request->conservar(), $request->user());

        return $request->expectsJson()
            ? $this->sobre(['desvinculado' => true])
            : back()->with('status', $request->conservar()
                ? __('Origen desvinculado. Los documentos se conservaron como normales.')
                : __('Origen desvinculado. Sus documentos se archivaron.'));
    }

    private function inspeccionarOFallar(VincularOrigenRequest $request): OrigenRemoto
    {
        try {
            return $this->origenes->inspeccionar($request->cuenta(), $request->validated('enlace'));
        } catch (NubeSyncException $e) {
            throw ValidationException::withMessages(['enlace' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDeInspeccion(OrigenRemoto $remoto): array
    {
        return [
            'tipo' => $remoto->tipo,
            'nombre' => $remoto->nombre,
            'archivos' => $remoto->archivos,
            'excel' => $remoto->excel,
            'truncado' => $remoto->truncado === null ? null : [
                'motivo' => $remoto->truncado->value,
                'mensaje' => __($remoto->truncado->mensaje()),
            ],
            'omitidos_total' => array_sum($remoto->omitidos),
            'omitidos' => collect($remoto->omitidos)->map(fn (int $cantidad, string $motivo): array => [
                'motivo' => $motivo,
                'etiqueta' => __(MotivoOmision::tryFrom($motivo)?->label() ?? $motivo),
                'cantidad' => $cantidad,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>|SeccionOrigenResource  $datos
     */
    private function sobre(array|SeccionOrigenResource $datos, int $estado = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $datos, 'error' => null, 'meta' => null], $estado);
    }
}
