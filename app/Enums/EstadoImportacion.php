<?php

namespace App\Enums;

enum EstadoImportacion: string
{
    case VistaPrevia = 'vista_previa';
    case Aplicada = 'aplicada';
    case Deshecha = 'deshecha';

    public function label(): string
    {
        return match ($this) {
            self::VistaPrevia => 'Pendiente de revisar',
            self::Aplicada => 'Aplicada',
            self::Deshecha => 'Deshecha',
        };
    }
}
