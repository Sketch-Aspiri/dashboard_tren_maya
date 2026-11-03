<?php

namespace App\Enums;

enum EstadoCuentaNube: string
{
    case Activa = 'activa';
    case RequiereReconexion = 'requiere_reconexion';

    public function label(): string
    {
        return match ($this) {
            self::Activa => 'Activa',
            self::RequiereReconexion => 'Requiere reconexión',
        };
    }
}
