<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarSolicitudesInsumosRequest;
use App\Http\Requests\ImportarRespuestaCoordinacionRequest;
use App\Http\Requests\RegistrarEnvioCoordinacionRequest;
use App\Models\SolicitudInsumo;
use App\Services\ConsolidadoInsumosService;
use App\Services\CoordinacionInsumosService;
use App\Services\SolicitudInsumoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Ciclo con la Coordinación (solo jefatura): registrar el envío del ANEXO con
 * su número de oficio e importar el Excel que la Coordinación devuelve, con la
 * partida final de cada renglón y los renglones que quitó o agregó. Controlador
 * delgado; la lógica vive en CoordinacionInsumosService.
 */
class CoordinacionInsumosController extends Controller
{
    public function __construct(
        private readonly CoordinacionInsumosService $service,
        private readonly SolicitudInsumoService $solicitudes,
    ) {}

    public function index(ConsultarSolicitudesInsumosRequest $request): View
    {
        $this->authorize('consolidate', SolicitudInsumo::class);

        $objetivo = $this->solicitudes->mesObjetivo();
        $anio = (int) ($request->validated('anio') ?? $objetivo['anio']);
        $mes = (int) ($request->validated('mes') ?? $objetivo['mes']);

        $solicitudes = SolicitudInsumo::query()->with('estacion')->where('anio', $anio)->where('mes', $mes)->get();

        return view('insumos.coordinacion', [
            'anio' => $anio,
            'mes' => $mes,
            'solicitudes' => $solicitudes->sortBy(fn (SolicitudInsumo $s) => $s->estacion->orden)->values(),
            'aprobadas' => $solicitudes->where('estado', SolicitudInsumo::APROBADA)->count(),
            'meses' => ConsolidadoInsumosService::MESES,
        ]);
    }

    public function enviar(RegistrarEnvioCoordinacionRequest $request): RedirectResponse
    {
        $enviadas = $this->service->registrarEnvio(
            (int) $request->validated('anio'),
            (int) $request->validated('mes'),
            $request->validated('oficio'),
            $request->user(),
        );

        return redirect()
            ->route('insumos.coordinacion.index', $request->safe()->only(['anio', 'mes']))
            ->with('status', trans_choice('Oficio :oficio registrado: :n solicitud enviada a la Coordinación.|Oficio :oficio registrado: :n solicitudes enviadas a la Coordinación.', $enviadas, ['oficio' => $request->validated('oficio'), 'n' => $enviadas]));
    }

    public function respuesta(ImportarRespuestaCoordinacionRequest $request): RedirectResponse
    {
        $destino = redirect()->route('insumos.coordinacion.index', $request->safe()->only(['anio', 'mes']));

        try {
            $resumen = $this->service->importarRespuesta(
                (int) $request->validated('anio'),
                (int) $request->validated('mes'),
                $request->file('archivo')->getRealPath(),
                $request->user(),
            );
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) {
                throw $e;
            }

            report($e);

            return $destino->withErrors(['archivo' => __('No se pudo leer el archivo. Verifica que sea el ANEXO .xlsx devuelto por la Coordinación.')]);
        }

        return $destino->with('resumen_coordinacion', $resumen)->with('status', __('Respuesta de la Coordinación aplicada a :n solicitud(es).', ['n' => $resumen['solicitudes']]));
    }
}
