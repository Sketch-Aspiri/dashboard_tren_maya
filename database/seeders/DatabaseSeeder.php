<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds roles only. The real Jefe de Zona account is never provisioned
 * with a known/default password via `migrate --seed` — it must be created
 * interactively via `php artisan app:create-zone-chief`, which prompts for
 * a fresh password on every environment. See CreateZoneChiefCommand.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
