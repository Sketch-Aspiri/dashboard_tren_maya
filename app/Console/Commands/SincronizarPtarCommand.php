<?php

namespace App\Console\Commands;

use App\Models\PtarPlanta;
use Illuminate\Console\Command;

/** Crea las plantas PTAR del catálogo (config/ptar.php) que falten. Es seguro repetirlo. */
class SincronizarPtarCommand extends Command
{
    protected $signature = 'ptar:sincronizar-plantas';

    protected $description = 'Crea las plantas de tratamiento (PTAR) del catálogo que aún no existen';

    public function handle(): int
    {
        $creadas = PtarPlanta::sincronizar();

        $this->info($creadas.' planta(s) creada(s); '.PtarPlanta::query()->count().' en total.');

        return self::SUCCESS;
    }
}
