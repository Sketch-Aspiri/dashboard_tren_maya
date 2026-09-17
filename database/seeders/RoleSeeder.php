<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

/**
 * Seeds the roles this project defines from day one, per CLAUDE.md
 * ("Roles y permisos desde el día uno, aunque hoy solo exista un usuario").
 */
class RoleSeeder extends Seeder
{
    /**
     * The roles this project supports.
     *
     * @var list<string>
     */
    public const ROLES = [
        'Jefe de Zona',
        'Administrador',
        'Estación',
    ];

    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
