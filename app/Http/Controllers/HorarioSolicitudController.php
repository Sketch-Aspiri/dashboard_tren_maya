<?php

namespace App\Http\Controllers;

use App\Http\Requests\CrearHorarioSolicitudRequest;
use App\Http\Requests\ResolverHorarioSolicitudRequest;
use App\Models\HorarioPeriodo;
use App\Models\HorarioSolicitud;
use App\Models\HorarioTurno;
use App\Services\Horarios\HorarioSolicitudService;
use App\Support\AlcanceEstacion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Solicitudes de cambio de horario: la estación pide y cancela las suyas; la
 * Zona autoriza o rechaza. Controlador delgado (lógica en HorarioSolicitudService).
 */
class HorarioSolicitudController extends Controller
{
    private const ESTADOS = [HorarioSolicitud::PENDIENTE, HorarioSolicitud::AUTORIZADA, HorarioSolicitud::RECHAZADA, HorarioSolicitud::CANCELADA];

    public function __construct(private readonly HorarioSolicitudService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', HorarioPeriodo::class);

        $usuario = $request->user();
        $estacionId = AlcanceEstacion::estacionId($usuario);
        $esZona = AlcanceEstacion::esTotal($usuario);
        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? (string) $request->query('estado') : HorarioSolicitud::PENDIENTE;

        $consulta = HorarioSolicitud::query()->with(['estacion:id,nombre', 'solicitante:id,name', 'resolvio:id,name'])->where('estado', $estado);

        if (! $esZona) {
            // Falla cerrada: sin estación asignada no se ve nada.
            $estacionId === null ? $consulta->whereRaw('1 = 0') : $consulta->where('estacion_id', $estacionId);
        }

        return view('horarios.solicitudes', [
            'solicitudes' => $consulta->orderByDesc('id')->paginate(20)->withQueryString(),
            'estado' => $estado,
            'estados' => self::ESTADOS,
            'esZona' => $esZona,
            'conteos' => $this->conteos($esZona, $estacionId),
        ]);
    }

    public function store(CrearHorarioSolicitudRequest $request, HorarioTurno $turno): RedirectResponse
    {
        $this->service->crear($request->user(), $turno, $request->validated());

        return redirect()->route('horarios.index', ['anio' => $turno->periodo->anio, 'mes' => $turno->periodo->mes, 'desde' => $turno->fecha->toDateString()])
            ->with('status', __('Solicitud enviada a Edificio. Te avisaremos cuando la resuelvan.'));
    }

    public function cancelar(HorarioSolicitud $solicitud, Request $request): RedirectResponse
    {
        $this->authorize('cancelar', $solicitud);

        $this->service->cancelar($solicitud, $request->user());

        return back()->with('status', __('Solicitud cancelada.'));
    }

    public function autorizar(ResolverHorarioSolicitudRequest $request, HorarioSolicitud $solicitud): RedirectResponse
    {
        $this->service->autorizar($solicitud, $request->user(), $request->validated('comentario'));

        return back()->with('status', __('Cambio autorizado: el horario ya se modificó.'));
    }

    public function rechazar(ResolverHorarioSolicitudRequest $request, HorarioSolicitud $solicitud): RedirectResponse
    {
        $this->service->rechazar($solicitud, $request->user(), (string) $request->validated('comentario'));

        return back()->with('status', __('Solicitud rechazada.'));
    }

    /**
     * @return array<string, int>
     */
    private function conteos(bool $esZona, ?int $estacionId): array
    {
        $consulta = HorarioSolicitud::query()->selectRaw('estado, count(*) as total')->groupBy('estado');

        if (! $esZona) {
            $estacionId === null ? $consulta->whereRaw('1 = 0') : $consulta->where('estacion_id', $estacionId);
        }

        return $consulta->pluck('total', 'estado')->map(fn ($n) => (int) $n)->all();
    }
}
