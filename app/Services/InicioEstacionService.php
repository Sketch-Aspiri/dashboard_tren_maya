<?php

namespace App\Services;

use App\Models\MenuGrupo;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Secciones\VisibilidadSecciones;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Datos de la pantalla de inicio de una cuenta Estación: sus secciones
 * aprobadas (con cuántas filas y documentos suyos tiene cada una) y si ya
 * capturó la asistencia de hoy.
 */
final class InicioEstacionService
{
    public function __construct(
        private readonly VisibilidadSecciones $visibilidad,
        private readonly AsistenciaCapturaService $asistencia,
    ) {}

    /**
     * @return array{secciones: Collection<int, array{grupo: MenuGrupo, secciones: Collection<int, Seccion>}>, asistencia: array{total_roster: int, total_capturado: int, capturado: bool}|null}
     */
    public function para(User $usuario): array
    {
        $ids = array_keys($this->visibilidad->idsVisiblesPara($usuario) ?? []);

        $secciones = Seccion::query()
            ->whereIn('id', $ids)
            ->with('grupo')
            ->withCount([
                'filas' => fn ($q) => $q->visiblePara($usuario),
                'archivos' => fn ($q) => $q->visiblePara($usuario),
            ])
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        $porGrupo = $secciones
            ->filter(fn (Seccion $s) => $s->grupo !== null)
            ->groupBy('menu_grupo_id')
            ->map(fn (Collection $delGrupo) => ['grupo' => $delGrupo->first()->grupo, 'secciones' => $delGrupo->values()])
            ->sortBy(fn (array $g) => [$g['grupo']->orden, $g['grupo']->id])
            ->values();

        return ['secciones' => $porGrupo, 'asistencia' => $this->asistenciaDeHoy($usuario)];
    }

    /**
     * @return array{total_roster: int, total_capturado: int, capturado: bool}|null
     */
    private function asistenciaDeHoy(User $usuario): ?array
    {
        if ($usuario->estacion_id === null) {
            return null;
        }

        $resumen = $this->asistencia->resumenDelDia(CarbonImmutable::today())
            ->first(fn (array $fila) => $fila['estacion']->id === $usuario->estacion_id);

        return $resumen === null ? null : [
            'total_roster' => $resumen['total_roster'],
            'total_capturado' => $resumen['total_capturado'],
            'capturado' => $resumen['capturado'],
        ];
    }
}
