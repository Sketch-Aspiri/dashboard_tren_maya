<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConsultarAfluenciaRequest;
use App\Http\Requests\GuardarAfluenciaRequest;
use App\Models\AfluenciaDiaria;
use App\Models\Estacion;
use App\Services\Afluencia\AfluenciaLibro;
use App\Services\Afluencia\AfluenciaService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Flujo de pasajeros: la Zona ve el tablero del mes y descarga el Excel; cada estación captura (y corrige)
 * sus abordan y boletos vendidos por día.
 */
class AfluenciaController extends Controller
{
    public function __construct(private readonly AfluenciaService $service) {}

    public function index(ConsultarAfluenciaRequest $request): View|RedirectResponse
    {
        [$anio, $mes] = $this->periodo($request);
        $usuario = $request->user();

        if ($usuario->hasRole('Estación')) {
            return redirect()->route('afluencia.estacion', ['estacion' => $usuario->estacion_id, 'anio' => $anio, 'mes' => $mes]);
        }

        return view('afluencia.index', [
            'anio' => $anio,
            'mes' => $mes,
            'tablero' => $this->service->tablero($anio, $mes),
            'puedeConsolidar' => $usuario->can('gestionar', AfluenciaDiaria::class),
        ]);
    }

    public function estacion(Estacion $estacion, ConsultarAfluenciaRequest $request): View
    {
        $this->authorize('manageFor', [AfluenciaDiaria::class, $estacion]);

        [$anio, $mes] = $this->periodo($request);
        $hoy = $this->service->mesActual();

        return view('afluencia.estacion', [
            'estacion' => $estacion,
            'anio' => $anio,
            'mes' => $mes,
            'dias' => $this->service->dias($estacion, $anio, $mes),
            'diasMes' => CarbonImmutable::create($anio, $mes, 1)->daysInMonth,
            'hastaDia' => $this->service->diasEsperados($anio, $mes),
            'hoy' => $hoy,
            'esZona' => $request->user()->can('gestionar', AfluenciaDiaria::class),
        ]);
    }

    public function guardar(Estacion $estacion, GuardarAfluenciaRequest $request): RedirectResponse
    {
        $this->authorize('manageFor', [AfluenciaDiaria::class, $estacion]);

        $r = $this->service->guardar($estacion, (int) $request->validated('anio'), (int) $request->validated('mes'), $request->validated('dias'), $request->user());

        return redirect()
            ->route('afluencia.estacion', ['estacion' => $estacion->id, 'anio' => $request->validated('anio'), 'mes' => $request->validated('mes')])
            ->with('status', __(':g día(s) guardado(s), :b borrado(s).', ['g' => $r['guardados'], 'b' => $r['borrados']]));
    }

    public function descargar(ConsultarAfluenciaRequest $request, AfluenciaLibro $libro): StreamedResponse
    {
        $this->authorize('gestionar', AfluenciaDiaria::class);

        $anio = (int) ($request->validated('anio') ?? $this->service->mesActual()['anio']);
        $hoja = $libro->anio($anio, $this->service->estaciones(), $this->service->anio($anio));

        activity('afluencia')->causedBy($request->user())->withProperties(['anio' => $anio])->log('excel descargado');

        return response()->streamDownload(
            fn () => (new Xlsx($hoja))->save('php://output'),
            sprintf('FLUJO_PASAJEROS_%04d.xlsx', $anio),
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function periodo(ConsultarAfluenciaRequest $request): array
    {
        $actual = $this->service->mesActual();

        return [(int) ($request->validated('anio') ?? $actual['anio']), (int) ($request->validated('mes') ?? $actual['mes'])];
    }
}
