<?php

namespace App\Support;

use App\Models\Seccion;
use Illuminate\Support\Collection;

/**
 * Convierte una lista plana de secciones en árbol (relación "hijas" ya
 * cargada, sin consultas por nivel) y la aplana con su profundidad para
 * listas tipo carpeta.
 */
final class ArbolSecciones
{
    /**
     * Devuelve las raíces; cada sección trae la relación "hijas" asignada.
     *
     * @param  Collection<int, Seccion>  $secciones
     * @return Collection<int, Seccion>
     */
    public static function construir(Collection $secciones): Collection
    {
        $porPadre = $secciones->groupBy(fn (Seccion $seccion) => $seccion->parent_id ?? 0);

        foreach ($secciones as $seccion) {
            $seccion->setRelation('hijas', $porPadre->get($seccion->id, collect())->values());
        }

        // Una hija cuyo padre no está en la lista (p. ej. padre oculto) no es raíz: queda fuera.
        return $porPadre->get(0, collect())->values();
    }

    /**
     * Recorrido en profundidad: cada elemento lleva su nivel (0 = raíz).
     *
     * @param  Collection<int, Seccion>  $raices
     * @return Collection<int, array{seccion: Seccion, nivel: int}>
     */
    public static function aplanar(Collection $raices, int $nivel = 0): Collection
    {
        return $raices->flatMap(fn (Seccion $seccion) => collect([['seccion' => $seccion, 'nivel' => $nivel]])
            ->concat(self::aplanar($seccion->getRelation('hijas'), $nivel + 1)))->values();
    }
}
