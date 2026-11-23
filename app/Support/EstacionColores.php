<?php

namespace App\Support;

/**
 * Un color fijo por estación para el calendario de vacaciones (según su orden en el catálogo, no cambia con los filtros).
 * Tonos distinguibles entre sí; cada chip además lleva el nombre de la estación, así que el color nunca es lo único.
 */
final class EstacionColores
{
    /** @var list<array{fondo: string, borde: string, texto: string}> */
    private const PALETA = [
        ['fondo' => '#dbeafe', 'borde' => '#2563eb', 'texto' => '#1e3a8a'], // azul
        ['fondo' => '#fee2e2', 'borde' => '#dc2626', 'texto' => '#7f1d1d'], // rojo
        ['fondo' => '#dcfce7', 'borde' => '#16a34a', 'texto' => '#14532d'], // verde
        ['fondo' => '#fef3c7', 'borde' => '#d97706', 'texto' => '#78350f'], // ámbar
        ['fondo' => '#ede9fe', 'borde' => '#7c3aed', 'texto' => '#4c1d95'], // violeta
        ['fondo' => '#cffafe', 'borde' => '#0891b2', 'texto' => '#164e63'], // cian
        ['fondo' => '#fce7f3', 'borde' => '#db2777', 'texto' => '#831843'], // rosa
        ['fondo' => '#ecfccb', 'borde' => '#65a30d', 'texto' => '#365314'], // lima
        ['fondo' => '#ffedd5', 'borde' => '#ea580c', 'texto' => '#7c2d12'], // naranja
        ['fondo' => '#e2e8f0', 'borde' => '#475569', 'texto' => '#0f172a'], // pizarra
    ];

    /**
     * @return array{fondo: string, borde: string, texto: string}
     */
    public static function para(int $orden, int $id = 0): array
    {
        $base = $orden > 0 ? $orden : $id;

        return self::PALETA[max($base - 1, 0) % count(self::PALETA)];
    }
}
