<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActualizarTopesVacacionRequest;
use App\Http\Requests\InhabilVacacionRequest;
use App\Models\Estacion;
use App\Models\VacacionCatalogoPeriodo;
use App\Models\VacacionDiaInhabil;
use App\Models\VacacionEstacionTope;
use App\Models\VacacionPlan;
use App\Services\Vacaciones\VacacionCatalogoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de periodos, días inhábiles y topes por estación del año de selección (solo la Zona).
 */
class VacacionCatalogoController extends Controller
{
    public function __construct(private readonly VacacionCatalogoService $catalogo) {}

    public function index(): View
    {
        $this->authorize('gestionar', VacacionPlan::class);
        $anio = (int) config('vacaciones.anio_seleccion');

        return view('vacaciones.seleccion.catalogo', [
            'anio' => $anio,
            'periodos' => VacacionCatalogoPeriodo::query()->where('anio', $anio)->orderBy('inicio')->orderBy('dias')->get()->groupBy('trimestre'),
            'inhabiles' => VacacionDiaInhabil::query()->where('anio', $anio)->orderBy('fecha')->get(),
            'estaciones' => Estacion::query()->orderBy('orden')->get(['id', 'nombre']),
            'topes' => VacacionEstacionTope::query()->where('anio', $anio)->pluck('max_simultaneos', 'estacion_id')->all(),
            'predeterminado' => VacacionEstacionTope::PREDETERMINADO,
        ]);
    }

    public function generar(): RedirectResponse
    {
        $this->authorize('gestionar', VacacionPlan::class);
        $r = $this->catalogo->generar((int) config('vacaciones.anio_seleccion'));

        return back()->with('status', __('Catálogo generado: :p periodos nuevos y :i días inhábiles nuevos.', ['p' => $r['periodos'], 'i' => $r['inhabiles']]));
    }

    public function periodo(VacacionCatalogoPeriodo $periodo): RedirectResponse
    {
        $this->authorize('gestionar', VacacionPlan::class);
        abort_unless($periodo->anio === (int) config('vacaciones.anio_seleccion'), 404);

        $autorizado = ! $periodo->autorizado;

        if (! $autorizado && $periodo->selecciones()->exists()) {
            return back()->withErrors(['plan' => __('Ese periodo ya fue elegido por alguien; no se puede desautorizar.')]);
        }

        $periodo->update(['autorizado' => $autorizado]);

        return back()->with('status', $autorizado ? __('Periodo autorizado.') : __('Periodo marcado como no autorizado.'));
    }

    public function topes(ActualizarTopesVacacionRequest $request): RedirectResponse
    {
        $anio = (int) config('vacaciones.anio_seleccion');
        $validas = Estacion::query()->pluck('id')->all();

        foreach ($request->validated('topes') as $estacionId => $max) {
            if (in_array((int) $estacionId, $validas, true)) {
                VacacionEstacionTope::query()->updateOrCreate(['anio' => $anio, 'estacion_id' => (int) $estacionId], ['max_simultaneos' => (int) $max]);
            }
        }

        return back()->with('status', __('Topes guardados.'));
    }

    public function inhabilStore(InhabilVacacionRequest $request): RedirectResponse
    {
        VacacionDiaInhabil::query()->updateOrCreate(
            ['anio' => (int) config('vacaciones.anio_seleccion'), 'fecha' => $request->validated('fecha')],
            ['nombre' => $request->validated('nombre')],
        );

        return back()->with('status', __('Día inhábil guardado.'));
    }

    public function inhabilDestroy(Request $request, VacacionDiaInhabil $inhabil): RedirectResponse
    {
        $this->authorize('gestionar', VacacionPlan::class);
        $inhabil->delete();

        return back()->with('status', __('Día inhábil eliminado.'));
    }
}
