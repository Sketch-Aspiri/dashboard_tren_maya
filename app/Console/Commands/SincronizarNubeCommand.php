<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarOrigenJob;
use App\Models\SeccionOrigen;
use Illuminate\Console\Command;

class SincronizarNubeCommand extends Command
{
    protected $signature = 'secciones:sincronizar-nube';

    protected $description = 'Envía a la cola la sincronización de cada origen de nube activo (documentos vinculados)';

    public function handle(): int
    {
        $enviados = 0;

        SeccionOrigen::query()->activos()->each(function (SeccionOrigen $origen) use (&$enviados): void {
            SincronizarOrigenJob::dispatch($origen->id);
            $enviados++;
        });

        $this->info("Orígenes enviados a la cola: {$enviados}");

        return self::SUCCESS;
    }
}
