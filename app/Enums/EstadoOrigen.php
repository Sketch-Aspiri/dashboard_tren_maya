<?php

namespace App\Enums;

enum EstadoOrigen: string
{
    case Activo = 'activo';
    case Pausado = 'pausado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Pausado => 'Pausado',
        };
    }
}
