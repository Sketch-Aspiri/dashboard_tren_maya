<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListarRolVacacionesRequest;
use App\Models\Estacion;
use App\Services\RolVacacionesService;
use Illuminate\Contracts\View\View;

/**
 * "Agenda Zona Oriente" -> Rol de vacaciones: consulta del rol anual de
 * vacacionistas. Thin-controller shape per .claude/rules/code-style.md;
 * autorización en ListarRolVacacionesRequest (VacacionistaPolicy::viewAny).
 */
class RolVacacionesController extends Controller
{
    public function __construct(private readonly RolVacacionesService $service) {}

    public function index(ListarRolVacacionesRequest $request): View
    {
        $anios = $this->service->aniosDisponibles();
        $anio = (int) ($request->validated('anio') ?? $anios[0] ?? now()->year);

        return view('agenda.vacaciones.index', [
            'vacacionistas' => $this->service->paginate($anio, $request->validated()),
            'anio' => $anio,
            'anios' => $anios,
            'estaciones' => Estacion::query()->orderBy('orden')->get(['id', 'nombre']),
            'filtros' => $request->validated(),
        ]);
    }
}
