<?php

namespace App\Enums;

enum EstadoOrigen: string
{
    case Activo = 'activo';
    case Pausado = 'pausado';
    /** La cuenta de nube pidió reconectarse: no se reintenta hasta que el Administrador la reconecte. */
    case RequiereReconexion = 'requiere_reconexion';
    /** La carpeta o archivo ya no existe (o ya no está compartido) en la nube. */
    case OrigenEliminado = 'origen_eliminado';

    public function label(): string
    {
        return match ($this) {
            self::Activo => 'Activo',
            self::Pausado => 'Pausado',
            self::RequiereReconexion => 'Requiere reconexión',
            self::OrigenEliminado => 'Eliminado en la nube',
        };
    }

    /** Clases de la insignia en la pantalla. */
    public function insignia(): string
    {
        return match ($this) {
            self::Activo => 'bg-green-100 text-green-800',
            self::Pausado => 'bg-gray-100 text-gray-700',
            self::RequiereReconexion, self::OrigenEliminado => 'bg-amber-100 text-amber-800',
        };
    }
}
