<?php

namespace App\Services\Secciones;

use App\Models\Seccion;
use App\Models\User;
use App\Support\AlcanceEstacion;
use Illuminate\Support\Facades\DB;

/**
 * Qué secciones ve una cuenta Estación: las aprobadas para su estación (o
 * compartidas con todas) MÁS todas sus descendientes, salvo las ocultas y lo
 * que cuelga de ellas. Los roles de zona ven todo (devuelve null). Se registra
 * como singleton por petición: dos consultas, memoizadas por estación.
 */
final class VisibilidadSecciones
{
    /** @var array<int, array<int, true>> estación => ids visibles (solo para la petición en curso) */
    private array $memo = [];

    private ?object $peticionMemo = null;

    /**
     * @return array<int, true>|null null = sin restricción (roles de zona)
     */
    public function idsVisiblesPara(User $usuario): ?array
    {
        if (AlcanceEstacion::esTotal($usuario)) {
            return null;
        }

        $estacionId = AlcanceEstacion::estacionId($usuario);

        return $estacionId === null ? [] : $this->idsDeEstacion($estacionId);
    }

    public function esVisiblePara(User $usuario, Seccion $seccion): bool
    {
        $ids = $this->idsVisiblesPara($usuario);

        return $ids === null || isset($ids[$seccion->id]);
    }

    /**
     * Forma pública para los tests y para quien necesite invalidar tras aprobar.
     */
    public function olvidar(): void
    {
        $this->memo = [];
    }

    /**
     * @return array<int, true>
     */
    private function idsDeEstacion(int $estacionId): array
    {
        // El memo solo vale para la petición en curso: otra petición (o un test) lo recalcula.
        $peticion = request();

        if ($this->peticionMemo !== $peticion) {
            $this->memo = [];
            $this->peticionMemo = $peticion;
        }

        if (isset($this->memo[$estacionId])) {
            return $this->memo[$estacionId];
        }

        $secciones = Seccion::query()->get(['id', 'parent_id', 'is_visible', 'compartida_todas']);
        $aprobadas = DB::table('seccion_estacion')->where('estacion_id', $estacionId)->pluck('seccion_id')->flip();
        $hijasDe = $secciones->groupBy(fn (Seccion $s) => $s->parent_id ?? 0);

        $visibles = [];
        $pendientes = $secciones
            ->filter(fn (Seccion $s) => $s->is_visible && ($s->compartida_todas || $aprobadas->has($s->id)))
            ->pluck('id')
            ->all();

        // Recorrido por niveles hacia los descendientes visibles.
        while ($pendientes !== []) {
            $id = array_pop($pendientes);

            if (isset($visibles[$id])) {
                continue;
            }

            $visibles[$id] = true;

            foreach ($hijasDe->get($id, collect()) as $hija) {
                if ($hija->is_visible) {
                    $pendientes[] = $hija->id;
                }
            }
        }

        return $this->memo[$estacionId] = $visibles;
    }
}
