<?php

namespace App\Console\Commands;

use App\Models\SeccionArchivo;
use App\Services\Secciones\NubeSyncService;
use Illuminate\Console\Command;

class SincronizarNubeCommand extends Command
{
    protected $signature = 'secciones:sincronizar-nube';

    protected $description = 'Descarga de nuevo los documentos vinculados a la nube y actualiza los que cambiaron (archivo y tablas)';

    public function handle(NubeSyncService $nube): int
    {
        $conteo = [
            NubeSyncService::ACTUALIZADO => 0,
            NubeSyncService::SIN_CAMBIOS => 0,
            NubeSyncService::ERROR => 0,
            NubeSyncService::OCUPADO => 0,
        ];

        SeccionArchivo::query()
            ->whereNotNull('origen_url')
            ->where('sincronizacion_activa', true)
            ->with('seccion')
            ->each(function (SeccionArchivo $archivo) use ($nube, &$conteo): void {
                $resultado = $nube->sincronizar($archivo);
                $conteo[$resultado]++;

                if ($resultado === NubeSyncService::ERROR) {
                    $this->warn("{$archivo->nombre_original}: ".SeccionArchivo::query()->whereKey($archivo->id)->value('sincronizacion_error'));
                }
            });

        $this->info(sprintf(
            'Actualizados: %d · Sin cambios: %d · Con error: %d · Ocupados: %d',
            $conteo[NubeSyncService::ACTUALIZADO],
            $conteo[NubeSyncService::SIN_CAMBIOS],
            $conteo[NubeSyncService::ERROR],
            $conteo[NubeSyncService::OCUPADO],
        ));

        return self::SUCCESS;
    }
}
