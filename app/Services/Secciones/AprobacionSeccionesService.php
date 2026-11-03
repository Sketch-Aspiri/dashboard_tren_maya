<?php

namespace App\Services\Secciones;

use App\Enums\NivelAccesoEstacion;
use App\Models\Estacion;
use App\Models\Seccion;
use App\Models\SeccionTabla;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Permisos de las estaciones (Ver / Editar) sobre una sección completa
 * (para todas las estaciones y/o para cada una) o sobre una hoja. Los de la
 * sección se heredan a sus descendientes (VisibilidadSecciones). Cada cambio
 * deja un evento con el estado anterior y el nuevo.
 */
final class AprobacionSeccionesService
{
    public function __construct(private readonly VisibilidadSecciones $visibilidad) {}

    /**
     * @param  array<int, NivelAccesoEstacion>  $porEstacion  estación => nivel (ya validadas)
     */
    public function actualizar(Seccion $seccion, ?NivelAccesoEstacion $todas, array $porEstacion, User $usuario): void
    {
        $antes = $this->estadoSeccion($seccion);

        DB::transaction(function () use ($seccion, $todas, $porEstacion, $usuario): void {
            $seccion->forceFill(['acceso_todas' => $todas])->saveQuietly();
            $this->sincronizar($seccion->estacionesAprobadas(), $porEstacion, $usuario);
        });

        $this->visibilidad->olvidar();
        $despues = $this->estadoSeccion($seccion->refresh());

        if ($antes !== $despues) {
            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($seccion)
                ->event('seccion_compartida_estaciones')
                ->withProperties(['old' => $antes, 'attributes' => $despues])
                ->log('Cambiaron los permisos de las estaciones sobre la sección.');
        }
    }

    /**
     * @param  array<int, NivelAccesoEstacion>  $porEstacion  estación => nivel (ya validadas)
     */
    public function actualizarTabla(SeccionTabla $tabla, array $porEstacion, User $usuario): void
    {
        $antes = $this->niveles($tabla->estacionesAprobadas());

        DB::transaction(fn () => $this->sincronizar($tabla->estacionesAprobadas(), $porEstacion, $usuario));

        $this->visibilidad->olvidar();
        $despues = $this->niveles($tabla->estacionesAprobadas());

        if ($antes !== $despues) {
            activity('seccion')
                ->causedBy($usuario)
                ->performedOn($tabla->seccion)
                ->event('hoja_compartida_estaciones')
                ->withProperties(['tabla_id' => $tabla->id, 'tabla' => $tabla->nombre, 'old' => $antes, 'attributes' => $despues])
                ->log('Cambiaron los permisos de las estaciones sobre una hoja.');
        }
    }

    /**
     * @param  BelongsToMany<Estacion, *>  $relacion
     * @param  array<int, NivelAccesoEstacion>  $porEstacion
     */
    private function sincronizar(BelongsToMany $relacion, array $porEstacion, User $usuario): void
    {
        // Un permiso que no cambia conserva a quien lo dio; solo los nuevos o cambiados llevan al usuario actual.
        $actuales = (clone $relacion)->get(['estaciones.id'])->keyBy('id');

        $relacion->sync(collect($porEstacion)
            ->mapWithKeys(function (NivelAccesoEstacion $nivel, int $id) use ($actuales, $usuario): array {
                $pivot = $actuales->get($id)?->pivot;
                $sinCambio = $pivot !== null && $pivot->nivel === $nivel->value;

                return [$id => ['nivel' => $nivel->value, 'aprobado_por' => $sinCambio ? $pivot->aprobado_por : $usuario->id]];
            })
            ->all());
    }

    /**
     * @return array{todas: ?string, estaciones: array<string, string>}
     */
    private function estadoSeccion(Seccion $seccion): array
    {
        return [
            'todas' => $seccion->acceso_todas?->value,
            'estaciones' => $this->niveles($seccion->estacionesAprobadas()),
        ];
    }

    /**
     * @param  BelongsToMany<Estacion, *>  $relacion
     * @return array<string, string> nombre de la estación => nivel
     */
    private function niveles(BelongsToMany $relacion): array
    {
        return $relacion->orderBy('estaciones.orden')->get(['estaciones.id', 'estaciones.nombre'])
            ->mapWithKeys(fn (Estacion $estacion) => [$estacion->nombre => (string) $estacion->pivot->nivel])
            ->all();
    }
}
