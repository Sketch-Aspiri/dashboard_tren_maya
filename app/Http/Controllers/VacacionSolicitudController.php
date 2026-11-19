<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolverVacacionLoteRequest;
use App\Models\VacacionPeriodo;
use App\Models\VacacionSolicitud;
use App\Services\Vacaciones\VacacionEdicionService;
use App\Support\AlcanceEstacion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Solicitudes de cambio, agrupadas por envío (lote). La estación ve y cancela las suyas; la Zona resuelve.
 */
class VacacionSolicitudController extends Controller
{
    private const ESTADOS = [VacacionSolicitud::PENDIENTE, VacacionSolicitud::AUTORIZADA, VacacionSolicitud::RECHAZADA, VacacionSolicitud::CANCELADA];

    public function __construct(private readonly VacacionEdicionService $service) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', VacacionPeriodo::class);

        $usuario = $request->user();
        $esZona = AlcanceEstacion::esTotal($usuario);
        $estacionId = AlcanceEstacion::estacionId($usuario);
        $estado = in_array($request->query('estado'), self::ESTADOS, true) ? (string) $request->query('estado') : VacacionSolicitud::PENDIENTE;

        $base = VacacionSolicitud::query();

        if (! $esZona) {
            $estacionId === null ? $base->whereRaw('1 = 0') : $base->where('estacion_id', $estacionId);
        }

        $conteos = (clone $base)->selectRaw('estado, count(distinct lote) as total')->groupBy('estado')->pluck('total', 'estado')->map(fn ($n) => (int) $n)->all();
        $lotes = (clone $base)->where('estado', $estado)->select('lote')->groupBy('lote')->orderByRaw('max(id) desc')->paginate(15)->withQueryString();

        $solicitudes = VacacionSolicitud::query()->with(['estacion:id,nombre', 'solicitante:id,name', 'resolvio:id,name'])
            ->whereIn('lote', $lotes->pluck('lote'))->where('estado', $estado)->orderBy('id')->get()->groupBy('lote');

        return view('vacaciones.solicitudes', [
            'lotes' => $lotes,
            'solicitudes' => $solicitudes,
            'estado' => $estado,
            'estados' => self::ESTADOS,
            'esZona' => $esZona,
            'conteos' => $conteos,
        ]);
    }

    public function autorizar(ResolverVacacionLoteRequest $request, string $lote): RedirectResponse
    {
        abort_unless(Str::isUuid($lote), 404);
        $resueltas = $this->service->autorizarLote($lote, $request->user(), $request->validated('comentario'));

        return back()->with('status', __('Se autorizaron :n cambio(s) y el rol de vacaciones ya los refleja.', ['n' => $resueltas->count()]));
    }

    public function rechazar(ResolverVacacionLoteRequest $request, string $lote): RedirectResponse
    {
        abort_unless(Str::isUuid($lote), 404);
        $resueltas = $this->service->rechazarLote($lote, $request->user(), (string) $request->validated('comentario'));

        return back()->with('status', __('Se rechazaron :n cambio(s).', ['n' => $resueltas->count()]));
    }

    public function cancelar(Request $request, string $lote): RedirectResponse
    {
        $this->authorize('solicitar', VacacionPeriodo::class);
        abort_unless(Str::isUuid($lote), 404);
        $n = $this->service->cancelarLote($lote, $request->user());

        return back()->with('status', __('Se canceló el envío (:n cambio(s)).', ['n' => $n]));
    }
}
