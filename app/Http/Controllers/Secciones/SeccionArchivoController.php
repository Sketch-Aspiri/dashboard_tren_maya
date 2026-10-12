<?php

namespace App\Http\Controllers\Secciones;

use App\Http\Controllers\Controller;
use App\Http\Requests\Secciones\StoreSeccionArchivoRequest;
use App\Models\Seccion;
use App\Models\SeccionArchivo;
use App\Models\SeccionFila;
use App\Services\Secciones\ArchivoService;
use App\Services\Secciones\ImportacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos de una sección. Al soltar un archivo con tabla(s), además de
 * guardarlo se abre su vista previa de importación.
 */
class SeccionArchivoController extends Controller
{
    public function __construct(
        private readonly ArchivoService $archivos,
        private readonly ImportacionService $importaciones,
    ) {}

    public function store(StoreSeccionArchivoRequest $request, Seccion $seccion): JsonResponse|RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $fila = $request->validated('seccion_fila_id') ? SeccionFila::query()->find($request->validated('seccion_fila_id')) : null;
        $resultados = [];

        foreach ($request->file('archivos') as $subido) {
            $archivo = $this->archivos->guardar($seccion, $subido, $request->user(), $fila);
            // Un adjunto de fila nunca dispara importación: solo se guarda.
            $importacion = $fila ? null : $this->importaciones->desdeArchivo($seccion, $archivo, $request->user());

            $resultados[] = [
                'id' => $archivo->id,
                'nombre' => $archivo->nombre_original,
                'tablas' => $importacion ? count($importacion->tablas) : 0,
                'url_importacion' => $importacion ? route('secciones.importaciones.show', [$seccion, $importacion]) : null,
            ];
        }

        $conTabla = array_values(array_filter($resultados, fn (array $resultado) => $resultado['url_importacion'] !== null));
        $siguiente = count($conTabla) === 1 ? $conTabla[0]['url_importacion'] : null;

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'data' => ['archivos' => $resultados, 'redirigir' => $siguiente],
                'error' => null,
                'meta' => null,
            ], 201);
        }

        return $siguiente
            ? redirect()->to($siguiente)
            : back()->with('status', trans_choice('{1} Documento guardado.|[2,*] :count documentos guardados.', count($resultados)));
    }

    public function show(Request $request, Seccion $seccion, SeccionArchivo $archivo): StreamedResponse
    {
        $this->authorize('view', $seccion);

        $enLinea = $request->boolean('ver') && $archivo->admiteVistaPrevia();

        activity('seccion')
            ->causedBy($request->user())
            ->performedOn($archivo)
            ->event($enLinea ? 'documento_visto' : 'documento_descargado')
            ->log('Consulta de documento de sección.');

        $disco = Storage::disk($this->archivos->disco());
        $cabeceras = ['X-Content-Type-Options' => 'nosniff'];

        return $enLinea
            ? $disco->response($archivo->ruta, $archivo->nombre_original, $cabeceras + ['Content-Type' => $archivo->mime])
            : $disco->download($archivo->ruta, $archivo->nombre_original, $cabeceras);
    }

    public function destroy(Seccion $seccion, SeccionArchivo $archivo): RedirectResponse
    {
        $this->authorize('gestionarDatos', $seccion);

        $this->archivos->eliminar($archivo);

        return back()->with('status', __('Documento ":nombre" eliminado.', ['nombre' => $archivo->nombre_original]));
    }
}
