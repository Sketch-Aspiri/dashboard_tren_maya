<?php

namespace App\Services\Secciones;

use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Aprobar una sección para estaciones: "todas" o una lista. Aprobar una
 * sección aprueba también sus descendientes (VisibilidadSecciones). Cada
 * cambio deja un evento con el estado anterior y el nuevo.
 */
final class AprobacionSeccionesService
{
    /**
     * @param  list<int>  $estacionIds  estaciones operativas (ya validadas)
     */
    public function actualizar(Seccion $seccion, bool $todas, array $estacionIds, User $usuario): void
    {
        $antes = $this->estado($seccion);

        DB::transaction(function () use ($seccion, $todas, $estacionIds, $usuario): void {
            $seccion->forceFill(['compartida_todas' => $todas])->saveQuietly();

            // Con "todas" la lista específica no aporta nada: se vacía para no dejar aprobaciones huérfanas.
            $seccion->estacionesAprobadas()->sync(
                $todas ? [] : collect($estacionIds)->mapWithKeys(fn (int $id) => [$id => ['aprobado_por' => $usuario->id]])->all()
            );
        });

        $despues = $this->estado($seccion->refresh());

        if ($antes === $despues) {
            return;
        }

        activity('seccion')
            ->causedBy($usuario)
            ->performedOn($seccion)
            ->event('seccion_compartida_estaciones')
            ->withProperties(['old' => $antes, 'attributes' => $despues])
            ->log('Cambió la aprobación de la sección para estaciones.');
    }

    /**
     * @return array{todas: bool, estaciones: list<string>}
     */
    private function estado(Seccion $seccion): array
    {
        return [
            'todas' => (bool) $seccion->compartida_todas,
            'estaciones' => Estacion::query()
                ->whereIn('id', DB::table('seccion_estacion')->where('seccion_id', $seccion->id)->select('estacion_id'))
                ->orderBy('orden')
                ->pluck('nombre')
                ->all(),
        ];
    }
}
