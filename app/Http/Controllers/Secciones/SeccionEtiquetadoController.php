<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\EtiquetarArchivoRequest;
use App\Http\Requests\Secciones\EtiquetarEstacionRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionTabla;
use App\Services\Secciones\EtiquetadoEstacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;

/**
 * Asignar la estación de las filas (en bloque, por columna) y de los
 * documentos. Solo roles de zona (SeccionPolicy::etiquetarEstacion).
 */
class SeccionEtiquetadoController extends Controller
{
    public function __construct(private readonly EtiquetadoEstacionService $etiquetado) {}

    public function previsualizar(EtiquetarEstacionRequest $request, Seccion $seccion, SeccionTabla $tabla): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->etiquetado->previsualizar($tabla, $request->columna(), $request->sobrescribir()),
            'error' => null,
            'meta' => null,
        ]);
    }

    public function aplicar(EtiquetarEstacionRequest $request, Seccion $seccion, SeccionTabla $tabla): RedirectResponse
    {
        $resultado = $this->etiquetado->aplicar($tabla, $request->columna(), $request->sobrescribir(), $request->user());

        return back()->with('status', __(':n filas etiquetadas; :m sin coincidencia.', [
            'n' => array_sum($resultado['por_estacion']),
            'm' => $resultado['sin_coincidencia'],
        ]));
    }

    public function archivo(EtiquetarArchivoRequest $request, Seccion $seccion, SeccionArchivo $archivo): RedirectResponse
    {
        $archivo->forceFill(['estacion_id' => $request->validated('estacion_id')])->save();

        return back()->with('status', __('Estación del documento actualizada.'));
    }
}
