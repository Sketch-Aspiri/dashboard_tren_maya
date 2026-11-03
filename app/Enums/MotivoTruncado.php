<?php

namespace App\Enums;

/**
 * Por qué un listado de la nube se cortó antes de terminar. Con un listado
 * truncado el motor no archiva documentos (podrían seguir existiendo).
 */
enum MotivoTruncado: string
{
    case Peticiones = 'tope_de_peticiones';
    case Entradas = 'tope_de_entradas';
    case Tiempo = 'tope_de_tiempo';

    public function mensaje(): string
    {
        return match ($this) {
            self::Peticiones => 'El listado se cortó por exceder el máximo de peticiones a la nube.',
            self::Entradas => 'El listado se cortó por exceder el máximo de elementos revisados.',
            self::Tiempo => 'El listado se cortó por exceder el tiempo máximo.',
        };
    }
}
