<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\Hoja\ActualizarColumnaHojaRequest;
use App\Http\Requests\Secciones\Hoja\EliminarFilasHojaRequest;
use App\Http\Requests\Secciones\Hoja\GuardarCeldasRequest;
use App\Http\Requests\Secciones\Hoja\GuardarDisenoHojaRequest;
use App\Http\Requests\Secciones\Hoja\InsertarColumnaHojaRequest;
use App\Http\Requests\Secciones\Hoja\InsertarFilasHojaRequest;
use App\Http\Requests\Secciones\Hoja\OrdenarHojaRequest;
use App\Http\Requests\Secciones\Hoja\VerHojaRequest;
use App\Models\Seccion;
use App\Models\SeccionColumna;
use App\Models\SeccionTabla;
use App\Models\User;
use App\Services\Secciones\Hoja\DisenoHojaService;
use App\Services\Secciones\Hoja\EstructuraHojaService;
use App\Services\Secciones\Hoja\HojaCalculoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints JSON de la hoja de cálculo (cuadrícula tipo Excel de una tabla
 * de la sección). La autorización vive en las Form Requests (editarHoja,
 * modificarTabla) y en SeccionPolicy; aquí solo se delega y se responde con
 * el sobre {success, data, error, meta}.
 */
class SeccionHojaController extends Controller
{
    public function __construct(
        private readonly HojaCalculoService $hoja,
        private readonly EstructuraHojaService $estructura,
        private readonly DisenoHojaService $diseno,
    ) {}

    public function show(VerHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $tabla = $request->tabla();
        $usuario = $request->user();

        return $this->respuesta($this->hoja->datos($tabla, $usuario) + [
            'permisos' => $this->permisos($usuario, $seccion, $tabla),
        ]);
    }

    public function celdas(GuardarCeldasRequest $request, Seccion $seccion): JsonResponse
    {
        $filas = $this->hoja->guardarCeldas($request->tabla(), $request->cambios(), $request->user());

        return $this->respuesta(['filas' => (object) $filas]);
    }

    public function insertarFilas(InsertarFilasHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $ids = $this->estructura->insertarFilas(
            $request->tabla(),
            $request->validated('antes_de') === null ? null : (int) $request->validated('antes_de'),
            (int) $request->validated('cantidad'),
            $request->user(),
        );

        return $this->respuesta(['ids' => $ids], 201);
    }

    public function eliminarFilas(EliminarFilasHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $this->estructura->eliminarFilas($request->tabla(), $request->filas());

        return $this->respuesta(null);
    }

    public function insertarColumna(InsertarColumnaHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $columna = $this->estructura->insertarColumna($request->tabla(), $request->validated('antes_de'), $request->validated('nombre'));

        return $this->respuesta(['columna' => ['id' => $columna->id, 'clave' => $columna->clave, 'nombre' => $columna->nombre]], 201);
    }

    public function actualizarColumna(ActualizarColumnaHojaRequest $request, Seccion $seccion, SeccionColumna $columna): JsonResponse
    {
        $columna->update($request->validated());

        return $this->respuesta(['columna' => ['id' => $columna->id, 'nombre' => $columna->nombre, 'ancho' => $columna->ancho]]);
    }

    public function eliminarColumna(Request $request, Seccion $seccion, SeccionColumna $columna): JsonResponse
    {
        $this->authorize('update', $seccion);
        $this->authorize('modificarTabla', [$seccion, $columna->tabla]);

        $this->estructura->eliminarColumna($columna);

        return $this->respuesta(null);
    }

    public function diseno(GuardarDisenoHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $tabla = $request->tabla();
        $this->diseno->guardar($tabla, $request->combinaciones(), $request->tablas(), $tabla->filas()->count());

        return $this->respuesta(['diseno' => $tabla->refresh()->disenoHoja()]);
    }

    public function ordenar(OrdenarHojaRequest $request, Seccion $seccion): JsonResponse
    {
        $this->estructura->ordenar($request->tabla(), $request->columna(), $request->validated('direccion'));

        return $this->respuesta(null);
    }

    /**
     * Qué puede hacer el usuario en la cuadrícula (el servidor lo vuelve a
     * revisar en cada petición; esto solo decide qué botones se muestran).
     *
     * @return array{editar: bool, estructura: bool}
     */
    private function permisos(User $usuario, Seccion $seccion, SeccionTabla $tabla): array
    {
        return [
            'editar' => $usuario->can('editarHoja', [$seccion, $tabla]),
            'estructura' => $usuario->can('update', $seccion) && $usuario->can('modificarTabla', [$seccion, $tabla]),
        ];
    }

    private function respuesta(mixed $datos, int $estado = 200): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $datos, 'error' => null, 'meta' => null], $estado);
    }
}
