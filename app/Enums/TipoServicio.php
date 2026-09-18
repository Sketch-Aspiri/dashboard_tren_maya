<?php

namespace App\Enums;

/**
 * Servicio cuyo pago se reporta en "Estadísticas" -> Gasto energético
 * (ANEXO B, informe de reducción de energía eléctrica): agua y energía
 * eléctrica de cada estación.
 */
enum TipoServicio: string
{
    case EnergiaElectrica = 'energia_electrica';
    case Agua = 'agua';

    public function label(): string
    {
        return match ($this) {
            self::EnergiaElectrica => 'Energía eléctrica',
            self::Agua => 'Agua',
        };
    }
}
