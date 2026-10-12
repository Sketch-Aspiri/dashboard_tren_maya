<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Mueve un elemento una posición arriba/abajo dentro de su lista y
 * renumera "orden" de 1 a N (grupos del menú, secciones, columnas).
 */
final class Reordenador
{
    public const ARRIBA = 'arriba';

    public const ABAJO = 'abajo';

    /**
     * @param  Collection<int, Model>  $hermanos  lista completa ya ordenada, incluido $elemento
     */
    public static function mover(Collection $hermanos, Model $elemento, string $direccion): void
    {
        $lista = $hermanos->values()->all();
        $posicion = collect($lista)->search(fn (Model $item) => $item->is($elemento));

        if ($posicion === false) {
            return;
        }

        $destino = $direccion === self::ARRIBA ? $posicion - 1 : $posicion + 1;

        if ($destino >= 0 && $destino < count($lista)) {
            [$lista[$posicion], $lista[$destino]] = [$lista[$destino], $lista[$posicion]];
        }

        foreach ($lista as $indice => $item) {
            if ($item->getAttribute('orden') !== $indice + 1) {
                $item->forceFill(['orden' => $indice + 1])->save();
            }
        }
    }
}
