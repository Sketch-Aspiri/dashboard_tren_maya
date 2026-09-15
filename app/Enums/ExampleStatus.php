<?php

namespace App\Enums;

/**
 * Deliberately generic dummy statuses for the disposable reference CRUD
 * module (see CLAUDE.md). Replace with the real workflow states once the
 * Jefe de Zona data model is confirmed.
 */
enum ExampleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Draft = 'draft';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Inactive => 'Inactivo',
            self::Draft => 'Borrador',
        };
    }
}
