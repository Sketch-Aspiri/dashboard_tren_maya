<?php

namespace App\Services\Secciones;

use App\Enums\ModoImportacion;

/**
 * Decisiones del usuario al cargar de una vez todas las hojas de un libro:
 * un modo para toda la carga y, por cada hoja incluida, su mapeo de
 * columnas. Las hojas desmarcadas no aparecen en $hojas.
 */
final readonly class OpcionesLibro
{
    /**
     * @param  array<int, OpcionesImportacion>  $hojas  índice de hoja del documento => opciones
     */
    public function __construct(
        public ModoImportacion $modo,
        public array $hojas,
    ) {}

    /**
     * @param  array{modo: string, hojas: array<int|string, array<string, mixed>>}  $validado
     */
    public static function desdeArreglo(array $validado): self
    {
        $hojas = [];

        foreach ($validado['hojas'] as $indice => $hoja) {
            if (! ($hoja['incluir'] ?? false)) {
                continue;
            }

            $hojas[(int) $indice] = OpcionesImportacion::desdeArreglo([
                'tabla' => $indice,
                'fila_encabezado' => $hoja['fila_encabezado'],
                'mapeo' => $hoja['mapeo'],
                'tipos' => $hoja['tipos'] ?? [],
                'modo' => $validado['modo'],
                'llave' => $hoja['llave'] ?? null,
            ]);
        }

        return new self(ModoImportacion::from($validado['modo']), $hojas);
    }
}
