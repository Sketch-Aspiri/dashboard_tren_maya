<?php

namespace App\Console\Commands;

use App\Jobs\SincronizarOrigenJob;
use App\Models\SeccionOrigen;
use App\Services\Secciones\OrigenService;
use Illuminate\Console\Command;

class SincronizarNubeCommand extends Command
{
    protected $signature = 'secciones:sincronizar-nube
        {--origen= : Solo este origen (id)}
        {--ahora : Ejecuta en este proceso, sin pasar por la cola}';

    protected $description = 'Envía a la cola la sincronización de cada origen de nube activo (carpetas y documentos vinculados)';

    public function handle(OrigenService $origenes): int
    {
        $origenes->reactivarReconectados();

        $id = $this->option('origen');
        $consulta = SeccionOrigen::query()->when(
            $id !== null,
            fn ($q) => $q->whereKey((int) $id),
            fn ($q) => $q->activos(),
        );
        $enviados = 0;

        $consulta->each(function (SeccionOrigen $origen) use (&$enviados): void {
            $this->option('ahora')
                ? SincronizarOrigenJob::dispatchSync($origen->id)
                : SincronizarOrigenJob::dispatch($origen->id);
            $enviados++;
        });

        $this->info("Orígenes enviados a la cola: {$enviados}");

        return self::SUCCESS;
    }
}
