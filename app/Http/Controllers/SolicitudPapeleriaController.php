<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarSolicitudesPapeleriaRequest;
use App\Http\Requests\GuardarSolicitudPapeleriaRequest;
use App\Http\Requests\RevisarSolicitudPapeleriaRequest;
use App\Models\CatalogoPapeleria;
use App\Models\Estacion;
use App\Models\SolicitudPapeleria;
use App\Services\PapeleriaLibro;
use App\Services\SolicitudPapeleriaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Recursos materiales" -> Solicitud de papelería. La estación captura y
 * envía; la Zona aprueba o devuelve y descarga el Excel del formato oficial.
 * Controlador delgado: la lógica vive en SolicitudPapeleriaService.
 */
class SolicitudPapeleriaController extends Controller
{
    private const MESES_CORTOS = [1 => 'ENE', 2 => 'FEB', 3 => 'MAR', 4 => 'ABR', 5 => 'MAY', 6 => 'JUN', 7 => 'JUL', 8 => 'AGO', 9 => 'SEP', 10 => 'OCT', 11 => 'NOV', 12 => 'DIC'];

    public function __construct(
        private readonly SolicitudPapeleriaService $service,
        private readonly PapeleriaLibro $libro,
    ) {}

    /**
     * Zona: tablero del mes. Estación: va directo a su solicitud.
     */
    public function index(ConsultarSolicitudesPapeleriaRequest $request): View|RedirectResponse
    {
        [$anio, $mes] = $this->periodo($request);
        $usuario = $request->user();

        if ($usuario->hasRole('Estación')) {
            return redirect()->route('papeleria.estacion', ['estacion' => $usuario->estacion_id, 'anio' => $anio, 'mes' => $mes]);
        }

        return view('papeleria.index', [
            'anio' => $anio,
            'mes' => $mes,
            'tablero' => $this->service->tablero($anio, $mes),
            'puedeConsolidar' => $usuario->can('consolidate', SolicitudPapeleria::class),
        ]);
    }

    public function estacion(Estacion $estacion, ConsultarSolicitudesPapeleriaRequest $request): RedirectResponse
    {
        $this->authorize('manageFor', [SolicitudPapeleria::class, $estacion]);

        [$anio, $mes] = $this->periodo($request);
        $solicitud = $this->service->obtenerOCrear($estacion, $anio, $mes, $request->user());

        return redirect()->route('papeleria.show', $solicitud);
    }

    public function show(SolicitudPapeleria $solicitud, Request $request): View
    {
        $this->authorize('view', $solicitud);

        return view('papeleria.show', [
            'solicitud' => $solicitud->load(['estacion', 'partidas', 'revisor']),
            'puedeEditar' => $request->user()->can('update', $solicitud),
            'puedeRevisar' => $request->user()->can('review', $solicitud),
            'articulos' => CatalogoPapeleria::query()->where('activo', true)->orderBy('descripcion')->pluck('descripcion'),
            'unidades' => config('papeleria.unidades'),
        ]);
    }

    public function update(SolicitudPapeleria $solicitud, GuardarSolicitudPapeleriaRequest $request): RedirectResponse
    {
        $this->service->guardar($solicitud, $request->validated(), $request->user());

        return redirect()->route('papeleria.show', $solicitud)->with('status', __('Solicitud guardada correctamente.'));
    }

    public function enviar(SolicitudPapeleria $solicitud, Request $request): RedirectResponse
    {
        $this->authorize('update', $solicitud);

        $this->service->enviar($solicitud, $request->user());

        return redirect()->route('papeleria.show', $solicitud)->with('status', __('Solicitud enviada a la Zona para revisión.'));
    }

    public function revisar(SolicitudPapeleria $solicitud, RevisarSolicitudPapeleriaRequest $request): RedirectResponse
    {
        $this->service->revisar($solicitud, $request->validated(), $request->user());

        return redirect()->route('papeleria.show', $solicitud)->with('status', __('Revisión guardada.'));
    }

    /**
     * Excel del formato oficial con las solicitudes aprobadas del mes.
     */
    public function descargar(ConsultarSolicitudesPapeleriaRequest $request): StreamedResponse
    {
        $this->authorize('consolidate', SolicitudPapeleria::class);

        [$anio, $mes] = $this->periodo($request);
        $filas = $this->service->filasAprobadas($anio, $mes);
        $libro = $this->libro->resumen($filas);

        activity('solicitud_papeleria')->causedBy($request->user())
            ->withProperties(['anio' => $anio, 'mes' => $mes, 'renglones' => $filas->count()])->log('excel descargado');

        return response()->streamDownload(
            fn () => (new Xlsx($libro))->save('php://output'),
            'SOLICITUD PAPELERIA_'.self::MESES_CORTOS[$mes].'_'.now()->format('dmy').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function periodo(ConsultarSolicitudesPapeleriaRequest $request): array
    {
        $objetivo = $this->service->mesObjetivo();

        return [(int) ($request->validated('anio') ?? $objetivo['anio']), (int) ($request->validated('mes') ?? $objetivo['mes'])];
    }
}
