<?php

namespace App\Enums;

/**
 * Tipo de dato de una columna de una sección dinámica (seccion_columnas).
 * El valor se guarda ya normalizado en seccion_filas.datos (JSON): número
 * como int/float, fecha como "Y-m-d", sí/no como bool, teléfono como "983 168 7870", texto y lista como
 * string. La conversión vive en App\Services\Secciones\NormalizadorValores.
 */
enum TipoColumna: string
{
    case Texto = 'texto';
    case Numero = 'numero';
    case Fecha = 'fecha';
    case Booleano = 'booleano';
    case Lista = 'lista';
    case Telefono = 'telefono';

    public function label(): string
    {
        return match ($this) {
            self::Texto => 'Texto',
            self::Numero => 'Número',
            self::Fecha => 'Fecha',
            self::Booleano => 'Sí / No',
            self::Lista => 'Lista de opciones',
            self::Telefono => 'Teléfono',
        };
    }
}
